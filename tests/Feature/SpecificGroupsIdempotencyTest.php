<?php

namespace Condoedge\Communications\Tests\Feature;

use Condoedge\Communications\EventsHandling\CommunicationTriggeredListener;
use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Condoedge\Communications\Models\CommunicationSending;
use Condoedge\Communications\Models\CommunicationTemplate;
use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Tests\Stubs\Deadline;
use Condoedge\Communications\Tests\TestCase;
use Condoedge\Communications\Triggers\ManualTrigger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * An event that names its groups is deduplicated only when it also declares an idempotency key.
 */
class SpecificGroupsIdempotencyTest extends TestCase
{
    protected function groupFor(string $trigger): CommunicationTemplateGroup
    {
        $group = new CommunicationTemplateGroup;
        $group->trigger = $trigger;
        $group->title = 'Idempotency probe';
        $group->save();

        $template = new CommunicationTemplate;
        $template->type = CommunicationType::EMAIL;
        $template->template_group_id = $group->id;
        $template->subject = json_encode(['en' => 'Idempotency probe']);
        $template->content = json_encode(['en' => '<p>Idempotency probe</p>']);
        $template->save();

        return $group;
    }

    protected function handle(CommunicableEvent $event): void
    {
        (new CommunicationTriggeredListener)->handle($event);
    }

    public function test_the_same_keyed_event_handled_twice_sends_once(): void
    {
        Mail::fake();
        $group = $this->groupFor(KeyedGroupsEvent::class);

        $this->handle(new KeyedGroupsEvent([$group->id], 'occurrence-1'));
        $this->handle(new KeyedGroupsEvent([$group->id], 'occurrence-1'));

        $this->assertSame(1, CommunicationSending::count());
        Mail::assertSentCount(1);
    }

    public function test_two_different_keys_both_send(): void
    {
        Mail::fake();
        $group = $this->groupFor(KeyedGroupsEvent::class);

        $this->handle(new KeyedGroupsEvent([$group->id], 'occurrence-1'));
        $this->handle(new KeyedGroupsEvent([$group->id], 'occurrence-2'));

        $this->assertSame(2, CommunicationSending::count());
    }

    public function test_a_null_key_is_not_deduplicated(): void
    {
        Mail::fake();
        $group = $this->groupFor(KeyedGroupsEvent::class);

        $this->handle(new KeyedGroupsEvent([$group->id], null));
        $this->handle(new KeyedGroupsEvent([$group->id], null));

        $this->assertSame(2, CommunicationSending::count());
    }

    public function test_a_send_that_throws_releases_its_key(): void
    {
        Mail::fake();
        $group = $this->groupFor(KeyedGroupsEvent::class);

        $failing = new KeyedGroupsEvent([$group->id], 'occurrence-1');
        $failing->throwOnParams = true;

        try {
            $this->handle($failing);
            $this->fail('the send was expected to throw');
        } catch (\RuntimeException $e) {
        }

        $this->handle(new KeyedGroupsEvent([$group->id], 'occurrence-1'));

        $this->assertSame(1, CommunicationSending::count());
    }

    public function test_the_manual_trigger_handled_twice_sends_twice(): void
    {
        Mail::fake();
        $group = $this->groupFor(ManualTrigger::class);
        $recipient = ManualProbeRecipient::create(['email' => 'manual@example.test']);

        $this->handle(new ManualTrigger([$group->id], ManualProbeRecipient::class, [$recipient->id]));
        $this->handle(new ManualTrigger([$group->id], ManualProbeRecipient::class, [$recipient->id]));

        $this->assertSame(2, CommunicationSending::count());
        Mail::assertSentCount(2);
    }
}

class KeyedGroupsEvent implements CommunicableEvent
{
    public bool $throwOnParams = false;

    public function __construct(private array $groupIds, private ?string $key)
    {
    }

    public function getSpecificCommunicationsIds(): array
    {
        return $this->groupIds;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->key;
    }

    public function getParams(): array
    {
        if ($this->throwOnParams) {
            throw new \RuntimeException('This event cannot build its params.');
        }

        return [];
    }

    public function getCommunicables(): Collection|array
    {
        return [new KeyedProbeRecipient('keyed@example.test')];
    }

    public static function getName(): string
    {
        return 'Keyed groups event';
    }

    public static function validVariablesIds($specificField = null, $context = []): ?array
    {
        return null;
    }
}

class KeyedProbeRecipient implements EmailCommunicable
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
        return 'keyed_probe';
    }

    public function label()
    {
        return 'Keyed probe';
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

class ManualProbeRecipient extends Deadline implements EmailCommunicable
{
    public function getEmail()
    {
        return $this->email;
    }

    public function getContextKey()
    {
        return 'manual_probe';
    }

    public function label()
    {
        return 'Manual probe';
    }

    public function scopeValidForCommunication($query)
    {
        return $query;
    }

    public function scopeSearch($query, $search)
    {
        return $query;
    }
}
