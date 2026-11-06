<?php

namespace Condoedge\Communications\Tests\Feature;

use Condoedge\Communications\Models\CommunicationSending;
use Condoedge\Communications\Models\CommunicationSendingRecipientStatus;
use Condoedge\Communications\Models\CommunicationTemplate;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Recipients\EmailGroup;
use Condoedge\Communications\Recipients\RecipientGroupOverride;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\Layout\DefaultLayoutEmailCommunicable;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Support\Facades\Mail;

/**
 * The optional group seam on EmailCommunicable: one recipient, several addresses, one message.
 */
class EmailGroupSeamTest extends TestCase
{
    /** @return array<int, array<int, string>> one entry per message, holding that message's To: */
    protected function sentMessages(): array
    {
        return collect(Mail::getSymfonyTransport()->messages())
            ->map(fn ($sent) => collect($sent->getOriginalMessage()->getTo())
                ->map(fn ($address) => $address->getAddress())
                ->sort()
                ->values()
                ->all())
            ->values()
            ->all();
    }

    protected function dispatch(array $communicables)
    {
        $template = new CommunicationTemplate;
        $template->subject = json_encode(['en' => 'Seam probe']);
        $template->content = json_encode(['en' => '<p>Seam probe</p>']);

        return CommunicationType::EMAIL->handler($template)->notify(collect($communicables), []);
    }

    public function test_a_recipient_that_declares_nothing_is_not_a_group()
    {
        $this->assertFalse(EmailGroup::isGroup(new SeamSingleRecipient('solo@example.test')));
        $this->assertSame([], EmailGroup::addressesOf(new SeamSingleRecipient('solo@example.test')));
    }

    public function test_undeclared_recipients_still_get_one_message_each()
    {
        $this->dispatch([
            new SeamSingleRecipient('a@example.test'),
            new SeamSingleRecipient('b@example.test'),
        ]);

        $this->assertSame([['a@example.test'], ['b@example.test']], $this->sentMessages());
    }

    public function test_an_undeclared_recipient_with_an_unusable_address_is_skipped_not_sent()
    {
        $report = $this->dispatch([new SeamSingleRecipient('not-an-address')]);

        $this->assertSame([], $this->sentMessages());
        $this->assertSame(1, $report->countOf(CommunicationSendingRecipientStatus::SKIPPED));
        $this->assertSame('no-email-address', $report->outcomes()[0]['error']);
    }

    public function test_a_declared_group_receives_one_message_addressed_to_every_member()
    {
        $this->dispatch([new SeamGroupRecipient(['a@example.test', 'b@example.test', 'c@example.test'])]);

        $this->assertSame(
            [['a@example.test', 'b@example.test', 'c@example.test']],
            $this->sentMessages(),
        );
    }

    public function test_a_group_and_a_single_recipient_coexist_in_one_send()
    {
        $report = $this->dispatch([
            new SeamGroupRecipient(['a@example.test', 'b@example.test']),
            new SeamSingleRecipient('solo@example.test'),
        ]);

        $this->assertSame(
            [['a@example.test', 'b@example.test'], ['solo@example.test']],
            $this->sentMessages(),
        );

        $this->assertCount(2, $report->outcomes());
    }

    public function test_a_group_occupies_exactly_one_delivery_report_position()
    {
        $report = $this->dispatch([new SeamGroupRecipient(['a@example.test', 'b@example.test', 'c@example.test'])]);

        $this->assertCount(1, $report->outcomes());
        $this->assertSame(1, $report->countOf(CommunicationSendingRecipientStatus::SENT));
    }

    public function test_an_undeliverable_member_is_dropped_and_the_others_still_receive()
    {
        $report = $this->dispatch([new SeamGroupRecipient(['a@example.test', 'not-an-address', 'b@example.test'])]);

        $this->assertSame([['a@example.test', 'b@example.test']], $this->sentMessages());
        $this->assertSame(1, $report->countOf(CommunicationSendingRecipientStatus::SENT));
    }

    public function test_a_group_with_no_deliverable_member_is_skipped_with_the_same_reason_as_a_single()
    {
        $report = $this->dispatch([new SeamGroupRecipient(['not-an-address', ''])]);

        $this->assertSame([], $this->sentMessages());
        $this->assertSame(1, $report->countOf(CommunicationSendingRecipientStatus::SKIPPED));
        $this->assertSame('no-email-address', $report->outcomes()[0]['error']);
    }

    public function test_a_group_carries_one_locale_for_the_whole_message()
    {
        Mail::fake();

        $this->dispatch([new SeamLocalisedGroupRecipient(['a@example.test', 'b@example.test'], 'fr')]);

        Mail::assertSent(DefaultLayoutEmailCommunicable::class, function ($mailable) {
            $this->assertSame('fr', $mailable->locale);

            $this->assertSame(
                ['a@example.test', 'b@example.test'],
                collect($mailable->to)->pluck('address')->sort()->values()->all(),
            );

            return true;
        });

        Mail::assertSentCount(1);
    }

    public function test_only_the_group_override_declares_the_seam()
    {
        $inner = new SeamSingleRecipient('inner@example.test');

        $group = RecipientGroupOverride::forGroup($inner, ['a@example.test', 'b@example.test']);

        $this->assertTrue(EmailGroup::isGroup($group));
        $this->assertSame(['a@example.test', 'b@example.test'], $group->getEmailGroup());
        $this->assertSame($inner, $group->getInner());

        $this->assertFalse(
            EmailGroup::isGroup(\Condoedge\Communications\Recipients\RecipientOverride::for($inner)->withEmail('one@example.test')),
            'a plain RecipientOverride must never be a group: hosts build one per address deliberately',
        );
    }

    public function test_a_recipient_that_answers_every_method_through_call_is_not_a_group()
    {
        $catchAll = new SeamCatchAllRecipient('catch-all@example.test');

        $this->assertTrue(
            is_callable([$catchAll, EmailGroup::DECLARATION]),
            'the fixture stopped answering __call(), so it no longer separates is_callable from method_exists',
        );

        $this->assertFalse(
            EmailGroup::isGroup($catchAll),
            'a recipient that merely answers __call() must not be a group: that is every Eloquent model in every host',
        );

        $this->assertSame([], EmailGroup::addressesOf($catchAll));

        $this->dispatch([$catchAll]);

        $this->assertSame(
            [['catch-all@example.test']],
            $this->sentMessages(),
            'the catch-all recipient must still be mailed at its single address, not failed',
        );
    }

    public function test_a_groups_get_email_is_the_joined_list_and_is_not_a_mailbox()
    {
        $group = new SeamGroupRecipient(['a@example.test', 'b@example.test']);

        $this->assertSame('a@example.test, b@example.test', $group->getEmail());
        $this->assertFalse((bool) filter_var($group->getEmail(), FILTER_VALIDATE_EMAIL));
    }

    public function test_the_recipient_row_records_the_whole_group()
    {
        $sending = CommunicationSending::createOneForCommunicationTemplate(
            $this->savedTemplate(),
            collect([new SeamGroupRecipient(['a@example.test', 'b@example.test'])]),
        );

        $this->assertSame('a@example.test, b@example.test', $sending->recipients()->first()->email);
    }

    public function test_the_recipient_row_of_an_undeclared_recipient_is_unchanged()
    {
        $sending = CommunicationSending::createOneForCommunicationTemplate(
            $this->savedTemplate(),
            collect([new SeamSingleRecipient('solo@example.test')]),
        );

        $this->assertSame('solo@example.test', $sending->recipients()->first()->email);
    }

    public function test_an_over_long_group_is_elided_rather_than_truncated_mid_address()
    {
        $addresses = collect(range(1, 40))
            ->map(fn ($i) => 'somebody-with-a-long-name-' . $i . '@example.test')
            ->all();

        $sending = CommunicationSending::createOneForCommunicationTemplate(
            $this->savedTemplate(),
            collect([new SeamGroupRecipient($addresses)]),
        );

        $stored = $sending->recipients()->first()->email;

        $this->assertLessThanOrEqual(255, mb_strlen($stored));

        $this->assertSame(
            1,
            preg_match('/^(.*) \(\+(\d+) more\)$/', $stored, $matches),
            'an over-long list must say how many addresses it dropped, or the log silently understates the audience',
        );

        $kept = explode(', ', $matches[1]);

        foreach ($kept as $address) {
            $this->assertContains($address, $addresses, 'an address was cut in half rather than dropped whole');
        }

        $this->assertSame(count($addresses), count($kept) + (int) $matches[2]);
    }

    protected function savedTemplate(): CommunicationTemplate
    {
        $template = new CommunicationTemplate;
        $template->type = CommunicationType::EMAIL;
        $template->subject = json_encode(['en' => 'Seam probe']);
        $template->content = json_encode(['en' => '<p>Seam probe</p>']);
        $template->save();

        return $template;
    }
}

class SeamSingleRecipient implements EmailCommunicable
{
    public function __construct(private string $email)
    {
    }

    public function getEmail()
    {
        return $this->email;
    }

    public function getContextKey()
    {
        return 'seam_single';
    }

    public function label()
    {
        return 'Seam single';
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

class SeamGroupRecipient implements EmailCommunicable
{
    /** @param string[] $emails */
    public function __construct(private array $emails)
    {
    }

    public function getEmailGroup(): array
    {
        return $this->emails;
    }

    public function getEmail()
    {
        return implode(', ', $this->emails);
    }

    public function getContextKey()
    {
        return 'seam_group';
    }

    public function label()
    {
        return 'Seam group';
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

/**
 * A recipient that answers any method name through __call(), the way every Eloquent model does.
 */
class SeamCatchAllRecipient extends SeamSingleRecipient
{
    public function __call($method, $arguments)
    {
        throw new \BadMethodCallException('Call to undefined method ' . static::class . '::' . $method . '()');
    }
}

class SeamLocalisedGroupRecipient extends SeamGroupRecipient implements HasLocalePreference
{
    public function __construct(array $emails, private string $locale)
    {
        parent::__construct($emails);
    }

    public function preferredLocale()
    {
        return $this->locale;
    }
}
