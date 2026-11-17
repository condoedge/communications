<?php

namespace Condoedge\Communications\Tests\Feature;

use Condoedge\Communications\Models\CommunicationSendingRecipientStatus;
use Condoedge\Communications\Models\CommunicationTemplate;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Recipients\RecipientOverride;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\SmsCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\Layout\DefaultLayoutSmsCommunicable;
use Condoedge\Communications\Services\Sms\SmsMessage;
use Condoedge\Communications\Services\Sms\SmsSenderContract;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * The SMS channel: the phone is notified directly unless the host binds a sender, which then
 * receives one rendered message per recipient.
 */
class SmsChannelTest extends TestCase
{
    protected function template(CommunicationType $type = CommunicationType::SMS): CommunicationTemplate
    {
        $communication = new CommunicationTemplate;
        $communication->type = $type;
        $communication->subject = ['en' => 'Probe'];
        $communication->content = ['en' => '<p>Text <b>body</b></p>'];

        return $communication;
    }

    protected function dispatch(array $communicables, array $params = [])
    {
        return CommunicationType::SMS->handler($this->template())->notify(collect($communicables), $params);
    }

    protected function bindSender(): CapturingSmsSender
    {
        $sender = new CapturingSmsSender;

        app()->instance(SmsSenderContract::class, $sender);

        return $sender;
    }

    public function test_without_a_sender_the_phone_is_notified_directly()
    {
        Notification::fake();

        $report = $this->dispatch([new SmsProbeRecipient('+15145550001')]);

        $this->assertTrue(CommunicationType::SMS->sendable());
        $this->assertSame(1, $report->countOf(CommunicationSendingRecipientStatus::SENT));

        Notification::assertSentTo(
            new AnonymousNotifiable,
            DefaultLayoutSmsCommunicable::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['vonage'] === '+15145550001'
                && $notification->toVonage($notifiable)->content === 'Text body',
        );
    }

    public function test_a_bound_sender_receives_the_rendered_message_and_nothing_is_notified()
    {
        Notification::fake();
        $sender = $this->bindSender();

        $report = $this->dispatch([new SmsProbeRecipient('+15145550001')], ['campaign' => 'spring']);

        Notification::assertNothingSent();
        $this->assertSame(1, $report->countOf(CommunicationSendingRecipientStatus::SENT));
        $this->assertCount(1, $sender->messages);
        $this->assertSame('+15145550001', $sender->messages[0]->phone);
        $this->assertSame('Text body', $sender->messages[0]->content);
        $this->assertSame('spring', $sender->messages[0]->params['campaign']);
    }

    public function test_a_recipient_without_a_phone_is_skipped_and_the_others_still_go_out()
    {
        $sender = $this->bindSender();

        $report = $this->dispatch([
            new SmsProbeRecipient('+15145550001'),
            new SmsProbeRecipient(null),
            RecipientOverride::for(new PhonelessRecipient),
            new SmsProbeRecipient('+15145550004'),
        ]);

        $this->assertSame(['+15145550001', '+15145550004'], array_map(fn ($m) => $m->phone, $sender->messages));
        $this->assertSame(2, $report->countOf(CommunicationSendingRecipientStatus::SKIPPED));
        $this->assertSame(0, $report->countOf(CommunicationSendingRecipientStatus::FAILED));
        $this->assertSame('no-phone-number', $report->outcomes()[1]['error']);
        $this->assertSame('channel-not-accepted', $report->outcomes()[2]['error']);
    }

    public function test_a_sender_that_throws_fails_that_recipient_only()
    {
        $sender = $this->bindSender();
        $sender->refuses = '+15145550002';

        $report = $this->dispatch([new SmsProbeRecipient('+15145550002'), new SmsProbeRecipient('+15145550003')]);

        $this->assertSame(CommunicationSendingRecipientStatus::FAILED, $report->outcomes()[0]['status']);
        $this->assertSame(CommunicationSendingRecipientStatus::SENT, $report->outcomes()[1]['status']);
    }

    public function test_the_message_knows_the_log_row_of_its_recipient()
    {
        $sender = $this->bindSender();

        CommunicationType::SMS->handler($this->template())
            ->forRecipientRows([0 => 41, 1 => 42])
            ->notify(collect([new SmsProbeRecipient('+15145550001'), new SmsProbeRecipient('+15145550002')]));

        $this->assertSame([41, 42], array_map(fn ($m) => $m->params['communication_sending_recipient_id'], $sender->messages));
    }

    public function test_a_layout_given_per_channel_reaches_its_own_channel_only()
    {
        Mail::fake();
        $sender = $this->bindSender();

        $params = ['layout' => ['sms' => ShoutingSmsLayout::class]];

        $this->dispatch([new SmsProbeRecipient('+15145550001')], $params);
        $this->assertSame('TEXT BODY', $sender->messages[0]->content);

        $sender->messages = [];

        // A layout named for another channel only is not this channel's: the default renders.
        $this->dispatch([new SmsProbeRecipient('+15145550001')], ['layout' => ['email' => ShoutingSmsLayout::class]]);
        $this->assertSame('Text body', $sender->messages[0]->content);
    }

    public function test_a_single_layout_class_still_applies_as_before()
    {
        $sender = $this->bindSender();

        $this->dispatch([new SmsProbeRecipient('+15145550001')], ['layout' => ShoutingSmsLayout::class]);

        $this->assertSame('TEXT BODY', $sender->messages[0]->content);
    }
}

class CapturingSmsSender implements SmsSenderContract
{
    /** @var SmsMessage[] */
    public array $messages = [];

    public ?string $refuses = null;

    public function send(SmsMessage $message): void
    {
        if ($message->phone === $this->refuses) {
            throw new \RuntimeException('refused');
        }

        $this->messages[] = $message;
    }
}

class ShoutingSmsLayout extends DefaultLayoutSmsCommunicable
{
    public function content(): string
    {
        return mb_strtoupper(parent::content());
    }
}

class SmsProbeRecipient implements SmsCommunicable
{
    public function __construct(private ?string $phone)
    {
    }

    public function getPhone()
    {
        return $this->phone;
    }

    public function getContextKey()
    {
        return 'sms_probe';
    }

    public function label()
    {
        return 'Sms probe';
    }

    public function scopeValidForCommunication($query)
    {
        throw new \LogicException('not queryable');
    }

    public function scopeSearch($query, $search)
    {
        throw new \LogicException('not queryable');
    }
}

class PhonelessRecipient implements EmailCommunicable
{
    public function getEmail()
    {
        return 'phoneless@example.test';
    }

    public function getContextKey()
    {
        return 'phoneless';
    }

    public function label()
    {
        return 'Phoneless';
    }

    public function scopeValidForCommunication($query)
    {
        throw new \LogicException('not queryable');
    }

    public function scopeSearch($query, $search)
    {
        throw new \LogicException('not queryable');
    }
}
