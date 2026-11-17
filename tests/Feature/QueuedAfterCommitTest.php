<?php

namespace Condoedge\Communications\Tests\Feature;

use Condoedge\Communications\EventsHandling\CommunicationTriggeredListener;
use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Condoedge\Communications\Models\CommunicationSending;
use Condoedge\Communications\Models\CommunicationTemplate;
use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

/**
 * An event raised inside a database transaction reaches the queued listener once it commits.
 */
class QueuedAfterCommitTest extends TestCase
{
    protected CommunicationTemplateGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Event::listen(AfterCommitProbeEvent::class, CommunicationTriggeredListener::class);

        $this->group = new CommunicationTemplateGroup;
        $this->group->trigger = AfterCommitProbeEvent::class;
        $this->group->title = 'After commit probe';
        $this->group->save();

        $template = new CommunicationTemplate;
        $template->type = CommunicationType::EMAIL;
        $template->template_group_id = $this->group->id;
        $template->subject = json_encode(['en' => 'After commit probe']);
        $template->content = json_encode(['en' => '<p>After commit probe</p>']);
        $template->save();
    }

    public function test_an_event_raised_in_a_transaction_is_handled_when_it_commits(): void
    {
        DB::transaction(function () {
            event(new AfterCommitProbeEvent([$this->group->id]));

            $this->assertSame(0, CommunicationSending::count());
        });

        $this->assertSame(1, CommunicationSending::count());
        Mail::assertSentCount(1);
    }

    public function test_an_event_raised_in_a_transaction_that_rolls_back_is_not_handled(): void
    {
        DB::beginTransaction();
        event(new AfterCommitProbeEvent([$this->group->id]));
        DB::rollBack();

        $this->assertSame(0, CommunicationSending::count());
        Mail::assertNothingSent();
    }

    public function test_an_event_raised_outside_a_transaction_is_handled_at_once(): void
    {
        event(new AfterCommitProbeEvent([$this->group->id]));

        $this->assertSame(1, CommunicationSending::count());
    }

    public function test_the_listener_called_directly_inside_a_transaction_sends_at_once(): void
    {
        DB::transaction(function () {
            (new CommunicationTriggeredListener)->handle(new AfterCommitProbeEvent([$this->group->id]));

            $this->assertSame(1, CommunicationSending::count());
        });
    }
}

class AfterCommitProbeEvent implements CommunicableEvent
{
    public function __construct(private array $groupIds)
    {
    }

    public function getSpecificCommunicationsIds(): array
    {
        return $this->groupIds;
    }

    public function getParams(): array
    {
        return [];
    }

    public function getCommunicables(): Collection|array
    {
        return [new AfterCommitProbeRecipient];
    }

    public static function getName(): string
    {
        return 'After commit probe';
    }

    public static function validVariablesIds($specificField = null, $context = []): ?array
    {
        return null;
    }
}

class AfterCommitProbeRecipient implements EmailCommunicable
{
    public function getEmail()
    {
        return 'after-commit@example.test';
    }

    public function getContextKey()
    {
        return 'after_commit_probe';
    }

    public function label()
    {
        return 'After commit probe';
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
