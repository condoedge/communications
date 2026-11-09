<?php

namespace Condoedge\Communications\Tests\Feature;

use Condoedge\Communications\Components\CommunicationTemplateForm;
use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Condoedge\Communications\Models\CommunicationSendingRecipientStatus;
use Condoedge\Communications\Models\CommunicationTemplate;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Recipients\RecipientOverride;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\PushCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\Layout\DefaultLayoutPushCommunicable;
use Condoedge\Communications\Services\Push\PushMessage;
use Condoedge\Communications\Services\Push\PushSenderContract;
use Condoedge\Communications\Tests\TestCase;
use Condoedge\Utils\Facades\GlobalConfig;
use Illuminate\Support\Collection;

/**
 * The PUSH channel: a host-bound sender receives one typed message per recipient, rendered by a
 * layout the host can swap, and the channel only counts as sendable where that sender exists.
 */
class PushChannelTest extends TestCase
{
    protected CapturingPushSender $sender;

    protected function bindSender(): void
    {
        $this->sender = new CapturingPushSender;

        app()->instance(PushSenderContract::class, $this->sender);
    }

    protected function dispatch(array $communicables, array $params = [], array $template = [])
    {
        $communication = new CommunicationTemplate;
        $communication->type = CommunicationType::PUSH;
        // Arrays, not JSON strings: a string assigned to a translatable attribute becomes the
        // current locale's translation verbatim, braces and all.
        $communication->subject = ['en' => $template['subject'] ?? 'Push probe'];
        $communication->content = ['en' => $template['content'] ?? '<p>Push <b>body</b></p>'];

        return CommunicationType::PUSH->handler($communication)->notify(collect($communicables), $params);
    }

    // ENABLEMENT

    public function test_push_is_enabled_by_config_but_not_sendable_without_a_bound_sender()
    {
        $this->assertFalse(app()->bound(PushSenderContract::class), 'the harness must not bind a sender by default');

        $this->assertTrue(CommunicationType::PUSH->enabled(), 'enabled() is pure config; the binding is a separate question');
        $this->assertFalse(CommunicationType::PUSH->sendable());

        $this->bindSender();

        $this->assertTrue(CommunicationType::PUSH->sendable());
    }

    public function test_every_other_channel_is_sendable_whenever_it_is_enabled()
    {
        foreach (CommunicationType::cases() as $type) {
            if ($type === CommunicationType::PUSH) {
                continue;
            }

            $this->assertSame($type->enabled(), $type->sendable(), $type->name);
        }
    }

    public function test_the_handler_skips_everyone_rather_than_throwing_when_no_sender_is_bound()
    {
        $report = $this->dispatch([new PushProbeRecipient(1, ['tok-1'])]);

        $this->assertSame(1, $report->countOf(CommunicationSendingRecipientStatus::SKIPPED));
        $this->assertSame('no-push-sender', $report->outcomes()[0]['error']);
    }

    // DELIVERY

    public function test_a_recipient_receives_one_message_carrying_all_of_their_tokens()
    {
        $this->bindSender();

        $this->dispatch([new PushProbeRecipient(1, ['tok-1', 'tok-2'])]);

        $this->assertCount(1, $this->sender->messages);

        $message = $this->sender->messages[0];

        $this->assertInstanceOf(PushMessage::class, $message);
        $this->assertSame(['tok-1', 'tok-2'], $message->tokens);
        $this->assertSame('Push probe', $message->title);
        $this->assertSame('Push body', $message->body, 'the body is the content flattened to plain text');
    }

    public function test_blank_tokens_are_dropped_and_a_recipient_with_none_is_skipped_as_no_push_device()
    {
        $this->bindSender();

        $report = $this->dispatch([
            new PushProbeRecipient(1, ['', null, 'tok-1']),
            new PushProbeRecipient(2, []),
            new PushProbeRecipient(3, ['tok-3']),
        ]);

        $this->assertSame([['tok-1'], ['tok-3']], array_map(fn ($m) => $m->tokens, $this->sender->messages));

        $this->assertSame(2, $report->countOf(CommunicationSendingRecipientStatus::SENT));
        $this->assertSame(1, $report->countOf(CommunicationSendingRecipientStatus::SKIPPED));
        $this->assertSame('no-push-device', $report->outcomes()[1]['error'], 'the skip must land on position 1, the tokenless recipient');
    }

    public function test_the_body_is_cut_at_the_lock_screen_length_with_an_ellipsis()
    {
        $this->bindSender();

        $this->dispatch([new PushProbeRecipient(1, ['tok-1'])], [], ['content' => '<p>' . str_repeat('word ', 100) . '</p>']);

        $body = $this->sender->messages[0]->body;

        $this->assertLessThanOrEqual(240, mb_strwidth($body));
        $this->assertStringEndsWith('…', $body);
    }

    // TAP TARGET

    public function test_the_push_route_param_is_the_tap_target()
    {
        $this->bindSender();

        $this->dispatch([new PushProbeRecipient(1, ['tok-1'])], ['push_route' => '/invoices/42']);

        $this->assertSame('/invoices/42', $this->sender->messages[0]->data['route']);
    }

    public function test_without_a_param_the_trigger_decides_the_tap_target_per_recipient()
    {
        $this->bindSender();

        $this->dispatch(
            [new PushProbeRecipient(7, ['tok-7']), new PushProbeRecipient(8, ['tok-8'])],
            ['trigger_instance' => new PushRoutingTrigger],
        );

        $this->assertSame(
            ['/probes/7', '/probes/8'],
            array_map(fn ($m) => $m->data['route'], $this->sender->messages),
            'pushRoute() must see the per-recipient context, or a deep link cannot point at the recipient\'s own resource',
        );
    }

    public function test_an_explicit_push_route_wins_over_the_trigger()
    {
        $this->bindSender();

        $this->dispatch(
            [new PushProbeRecipient(7, ['tok-7'])],
            ['trigger_instance' => new PushRoutingTrigger, 'push_route' => '/elsewhere'],
        );

        $this->assertSame('/elsewhere', $this->sender->messages[0]->data['route']);
    }

    public function test_with_no_tap_target_the_data_carries_no_route_key()
    {
        $this->bindSender();

        $this->dispatch([new PushProbeRecipient(1, ['tok-1'])]);

        $this->assertArrayNotHasKey('route', $this->sender->messages[0]->data);
    }

    // LAYOUT

    public function test_the_android_channel_param_reaches_the_message()
    {
        $this->bindSender();

        $this->dispatch([new PushProbeRecipient(1, ['tok-1'])], ['push_channel' => 'reminders']);

        $this->assertSame('reminders', $this->sender->messages[0]->androidChannel);
    }

    public function test_a_host_swaps_the_layout_through_the_same_param_as_the_other_channels()
    {
        $this->bindSender();

        $this->dispatch([new PushProbeRecipient(1, ['tok-1'])], ['layout' => ShoutingPushLayout::class]);

        $this->assertSame('PUSH PROBE', $this->sender->messages[0]->title);
    }

    // WRAPPED RECIPIENTS

    public function test_an_override_forwards_the_tokens_of_the_recipient_it_wraps()
    {
        $this->bindSender();

        $this->dispatch([RecipientOverride::for(new PushProbeRecipient(1, ['tok-1']))]);

        $this->assertSame(['tok-1'], $this->sender->messages[0]->tokens);
    }

    public function test_an_override_around_a_recipient_without_push_is_not_pushed()
    {
        $this->bindSender();

        $report = $this->dispatch([RecipientOverride::for(new PushlessRecipient)]);

        $this->assertSame([], $this->sender->messages);
        $this->assertSame('channel-not-accepted', $report->outcomes()[0]['error']);
    }

    // EDITOR

    public function test_the_editor_keeps_a_restricted_channel_to_super_admins_until_the_host_opens_it()
    {
        $this->bindSender();

        $this->assertFalse(CommunicationTemplateForm::offeredInEditor(CommunicationType::PUSH), 'push ships restricted; no one is logged in here');
        $this->assertTrue(CommunicationTemplateForm::offeredInEditor(CommunicationType::EMAIL));

        GlobalConfig::set('communications_channels.push.public', true);

        $this->assertTrue(CommunicationTemplateForm::offeredInEditor(CommunicationType::PUSH));
    }

    public function test_a_channel_that_cannot_send_here_is_not_offered_in_the_editor()
    {
        GlobalConfig::set('communications_channels.push.public', true);

        $this->assertFalse(CommunicationTemplateForm::offeredInEditor(CommunicationType::PUSH), 'no sender bound: offering it would let admins write templates nobody can deliver');
    }
}

class CapturingPushSender implements PushSenderContract
{
    /** @var PushMessage[] */
    public array $messages = [];

    public function send(PushMessage $message): void
    {
        $this->messages[] = $message;
    }
}

class ShoutingPushLayout extends DefaultLayoutPushCommunicable
{
    protected function title(): string
    {
        return mb_strtoupper(parent::title());
    }
}

class PushProbeRecipient implements PushCommunicable
{
    public function __construct(public int $id, private array $tokens)
    {
    }

    public function getPushTokens(): array
    {
        return $this->tokens;
    }

    public function getContextKey()
    {
        return 'push_probe';
    }

    public function label()
    {
        return 'Push probe ' . $this->id;
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

class PushlessRecipient implements EmailCommunicable
{
    public function getEmail()
    {
        return 'pushless@example.test';
    }

    public function getContextKey()
    {
        return 'pushless';
    }

    public function label()
    {
        return 'Pushless';
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

/** A trigger that deep-links each recipient to their own resource. */
class PushRoutingTrigger implements CommunicableEvent
{
    public static function getName(): string
    {
        return 'Push routing';
    }

    public function pushRoute(array $params): ?string
    {
        return isset($params['push_probe']) ? '/probes/' . $params['push_probe']->id : null;
    }

    public function getParams(): array
    {
        return [];
    }

    public function getCommunicables(): Collection|array
    {
        return collect();
    }

    public static function validVariablesIds($specificField = null, $context = []): ?array
    {
        return null;
    }
}
