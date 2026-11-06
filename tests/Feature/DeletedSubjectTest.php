<?php

namespace Condoedge\Communications\Tests\Feature;

use Carbon\CarbonImmutable;
use Condoedge\Communications\EventsHandling\CommunicationTriggeredListener;
use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Condoedge\Communications\Reminders\ReminderOffset;
use Condoedge\Communications\Tests\Stubs\Deadline;
use Condoedge\Communications\Tests\Stubs\DeadlineApproaching;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * A queued trigger whose model is gone by the time the listener runs.
 */
class DeletedSubjectTest extends TestCase
{
    public function test_a_subject_deleted_before_the_listener_runs_drops_the_send()
    {
        Event::listen(SerializedDeadlineApproaching::class, CommunicationTriggeredListener::class);

        $deadline = Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);

        $event = new SerializedDeadlineApproaching(
            $deadline,
            ReminderOffset::before(7),
            CarbonImmutable::parse('2026-08-12'),
        );

        $deadline->delete();

        event($event);

        $this->assertEquals(0, DB::table('communication_sendings')->count());
    }
}

/**
 * DeadlineApproaching as a host raises it: a trigger that serializes its model for the queue.
 */
class SerializedDeadlineApproaching extends DeadlineApproaching implements CommunicableEvent
{
    use SerializesModels;

    public function getCommunicables(): Collection|array
    {
        return [$this->deadline];
    }

    public static function getName(): string
    {
        return 'Deadline approaching';
    }

    public static function validVariablesIds($specificField = null, $context = []): ?array
    {
        return null;
    }
}
