<?php

namespace Condoedge\Communications\Tests\Feature\Reminders;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Condoedge\Communications\Reminders\RearmPolicy;
use Condoedge\Communications\Reminders\ReminderLedger;
use Condoedge\Communications\Reminders\ReminderOffset;
use Condoedge\Communications\Reminders\ReminderScope;
use Condoedge\Communications\Tests\Stubs\Deadline;
use Condoedge\Communications\Tests\Stubs\DeadlineApproaching;
use Condoedge\Communications\Tests\Stubs\DeadlineReminder;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * The sweep, end to end.
 */
class SweeperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kompo-communications.reminders.scheduled' => [DeadlineReminder::class],
            'kompo-communications.reminders.default_hour' => 9,
        ]);
    }

    protected function sweep(array $options = [])
    {
        return $this->artisan('communications:send-reminders', array_merge([
            '--date' => '2026-08-05',
            '--hour' => 9,
        ], $options));
    }

    protected function dispatchesFor($subject)
    {
        return Event::dispatched(DeadlineApproaching::class)
            ->filter(fn ($dispatch) => $dispatch[0]->deadline->id === $subject->id);
    }

    public function test_a_due_subject_is_dispatched_and_claimed()
    {
        $subject = Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);

        Event::fake([DeadlineApproaching::class]);

        $this->sweep()->assertSuccessful();

        Event::assertDispatched(
            DeadlineApproaching::class,
            fn ($event) => $event->deadline->id === $subject->id,
        );

        $this->assertDatabaseHas('communication_reminder_claims', [
            'reminder_key' => 'test-deadline',
            'offset_key' => 'b7',
            'rearm_key' => 'once',
            'subject_id' => $subject->id,
            'anchor_on' => '2026-08-12',
        ]);
    }

    public function test_running_twice_sends_once()
    {
        $subject = Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);

        Event::fake([DeadlineApproaching::class]);

        $this->sweep()->assertSuccessful();
        $this->sweep()->assertSuccessful();

        $this->assertCount(1, $this->dispatchesFor($subject), 'the second run must find the offset already claimed');

        $this->assertEquals(
            1,
            DB::table('communication_reminder_claims')->where('subject_id', $subject->id)->count(),
        );
    }

    public function test_a_completed_send_is_marked_dispatched_with_its_key()
    {
        $subject = Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);

        $this->sweep()->assertSuccessful();

        $claim = DB::table('communication_reminder_claims')->where('subject_id', $subject->id)->first();

        $this->assertNotNull($claim->dispatched_at, 'a delivered claim must not look stalled');

        $expected = (new DeadlineApproaching(
            $subject,
            ReminderOffset::before(7),
            CarbonImmutable::parse('2026-08-12'),
        ))->getIdempotencyKey();

        $this->assertEquals($expected, $claim->dispatch_key, 'the ledger must store the key the dispatched event declared');
    }

    public function test_a_null_event_releases_the_claim()
    {
        config(['kompo-communications.reminders.scheduled' => [SilentReminder::class]]);

        $subject = Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);

        Event::fake([DeadlineApproaching::class]);

        $this->sweep()->expectsOutputToContain('0 to send, 1 released')->assertSuccessful();

        $this->assertEquals(0, DB::table('communication_reminder_claims')->count());

        config(['kompo-communications.reminders.scheduled' => [DeadlineReminder::class]]);

        $this->sweep()->assertSuccessful();

        $this->assertCount(1, $this->dispatchesFor($subject), 'a released record must still be reachable');
    }

    public function test_a_subject_whose_anchor_disappears_is_never_claimed()
    {
        config(['kompo-communications.reminders.scheduled' => [AnchorlessReminder::class]]);

        Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);

        $this->sweep()->expectsOutputToContain('0 to send, 1 without an anchor')->assertSuccessful();

        $this->assertEquals(0, DB::table('communication_reminder_claims')->count());
    }

    public function test_a_claim_held_by_another_run_makes_this_run_stand_down()
    {
        $subject = Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);

        app(ReminderLedger::class)->claim(
            'test-deadline',
            ReminderOffset::before(7),
            'once',
            $subject,
            CarbonImmutable::parse('2026-08-12'),
            null,
            null,
        );

        Event::fake([DeadlineApproaching::class]);

        $this->sweep()->expectsOutputToContain('0 to send, 1 held by another run')->assertSuccessful();

        Event::assertNotDispatched(DeadlineApproaching::class);

        $this->assertNull(
            DB::table('communication_reminder_claims')->where('subject_id', $subject->id)->value('dispatched_at'),
            'standing down must not stamp another run\'s claim as delivered',
        );
    }

    public function test_a_reminder_that_throws_keeps_its_claim_and_is_never_reconsidered()
    {
        config(['kompo-communications.reminders.scheduled' => [ExplodingReminder::class]]);

        $subject = Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);

        Event::fake([DeadlineApproaching::class]);

        $this->sweep()->assertSuccessful();

        $claim = DB::table('communication_reminder_claims')->where('subject_id', $subject->id)->first();

        $this->assertNotNull($claim, 'the claim STANDS - a reminder that throws must not come back every hour');
        $this->assertNull($claim->dispatched_at, 'nothing was delivered, so nothing may be stamped as delivered');

        config(['kompo-communications.reminders.scheduled' => [DeadlineReminder::class]]);

        $this->sweep()->assertSuccessful();

        Event::assertNotDispatched(DeadlineApproaching::class);
    }

    public function test_a_moved_anchor_does_not_re_arm_under_the_default_policy()
    {
        $subject = Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);

        Event::fake([DeadlineApproaching::class]);

        $this->sweep()->assertSuccessful();

        $subject->update(['due_on' => '2026-09-11']);

        $this->sweep(['--date' => '2026-09-04'])
            ->expectsOutputToContain('0 to send, 1 already handled')
            ->assertSuccessful();

        $this->assertCount(1, $this->dispatchesFor($subject));
    }

    public function test_a_moved_anchor_does_re_arm_under_the_per_anchor_policy()
    {
        config(['kompo-communications.reminders.scheduled' => [PerAnchorSweepReminder::class]]);

        $subject = Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);

        Event::fake([DeadlineApproaching::class]);

        $this->sweep()->assertSuccessful();

        $subject->update(['due_on' => '2026-09-11']);

        $this->sweep(['--date' => '2026-09-04'])->assertSuccessful();

        $this->assertCount(
            2,
            $this->dispatchesFor($subject),
            'a deliberately extended deadline is something the recipient is entitled to be re-told',
        );
    }

    public function test_the_deadline_in_the_event_is_the_stored_anchor()
    {
        config(['kompo-communications.reminders.scheduled' => [MisreadAnchorReminder::class]]);

        $subject = Deadline::create(['due_on' => '2026-09-04', 'email' => 'a@b.test']);

        Event::fake([DeadlineApproaching::class]);

        $this->sweep()->assertSuccessful();

        $dispatched = $this->dispatchesFor($subject)->first()[0];

        $this->assertEquals('2026-09-07', $dispatched->getParams()['deadline_date']);
        $this->assertEquals('30', $dispatched->getParams()['days_left']);

        $this->assertEquals(
            '2026-09-07',
            DB::table('communication_reminder_claims')->where('subject_id', $subject->id)->value('anchor_on'),
        );
    }
}

/**
 * Always declines to send.
 */
class SilentReminder extends DeadlineReminder
{
    public function eventFor(Model $subject, ReminderOffset $offset, CarbonInterface $anchorOn, ReminderScope $scope): ?object
    {
        return null;
    }
}

/**
 * Its subjects never have an anchor, however the query selected them.
 */
class AnchorlessReminder extends DeadlineReminder
{
    public function anchorFor(Model $subject): ?CarbonInterface
    {
        return null;
    }
}

/**
 * The PER_ANCHOR_DATE opt-in at sweep level.
 */
class PerAnchorSweepReminder extends DeadlineReminder
{
    protected function rearmPolicy(): RearmPolicy
    {
        return RearmPolicy::PER_ANCHOR_DATE;
    }
}

/**
 * A reminder whose anchorFor() deliberately disagrees with the column its query selected on.
 */
class MisreadAnchorReminder extends DeadlineReminder
{
    protected function offsets(): array
    {
        return [ReminderOffset::before(30)];
    }

    public function anchorFor(Model $subject): ?CarbonInterface
    {
        return CarbonImmutable::parse($subject->due_on)->addDays(3);
    }
}

/**
 * Cannot build its event at all, so the sweeper's dispatch catch is the only thing that runs.
 */
class ExplodingReminder extends DeadlineReminder
{
    public function eventFor(
        Model $subject,
        ReminderOffset $offset,
        CarbonInterface $anchorOn,
        ReminderScope $scope,
    ): ?object {
        throw new \RuntimeException('This reminder cannot build its event.');
    }
}
