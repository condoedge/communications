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
 *
 * test_running_twice_sends_once is the regression gate for this entire plan. A nightly query that
 * keeps matching would otherwise email the same person every night for ninety nights, and the
 * claim ledger is the only thing preventing it.
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

    /** The dispatches that concern one subject. Every count assertion below filters the same way. */
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

    /** The headline. Two consecutive runs, exactly one send. */
    public function test_running_twice_sends_once()
    {
        $subject = Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);

        // Event::fake() fakes the DISPATCHER only. The ledger still writes, which is the entire
        // reason this test can distinguish "claimed once" from "sent once" — faking the ledger
        // too, or seeding its row by hand, would leave the second run's claim() unexercised and
        // the test would pass with the once-only guarantee deleted.
        Event::fake([DeadlineApproaching::class]);

        $this->sweep()->assertSuccessful();
        $this->sweep()->assertSuccessful();

        $this->assertCount(1, $this->dispatchesFor($subject), 'the second run must find the offset already claimed');

        $this->assertEquals(
            1,
            DB::table('communication_reminder_claims')->where('subject_id', $subject->id)->count(),
        );
    }

    /**
     * The claim is stamped as delivered, and carries what went out.
     *
     * No Event::fake() here on purpose: markDispatched()'s key comes off the real object event()
     * was handed, and a faked dispatcher would let a mutant that stores the wrong object's key
     * pass. The real dispatch is inert — the provider listens only on
     * config('kompo-communications.triggers'), and DeadlineApproaching is not in it and is not a
     * CommunicableEvent, so nothing is subscribed to it.
     */
    public function test_a_completed_send_is_marked_dispatched_with_its_key()
    {
        $subject = Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);

        $this->sweep()->assertSuccessful();

        $claim = DB::table('communication_reminder_claims')->where('subject_id', $subject->id)->first();

        $this->assertNotNull($claim->dispatched_at, 'a delivered claim must not look stalled');

        // FIVE components, not four. The subject key is the last one, and it is what keeps forty
        // subjects due on the same day from sharing one idempotency key and losing thirty-nine
        // sends behind an info-level log line — see RemindsAbout::getIdempotencyKey(). Asked of a
        // real event rather than spelled out as a literal, because the FORMAT belongs to
        // RemindsAbout and is pinned there by RemindsAboutTest; only the STORING belongs here, and
        // a second copy of the format would be free to drift from the first.
        $expected = (new DeadlineApproaching(
            $subject,
            ReminderOffset::before(7),
            CarbonImmutable::parse('2026-08-12'),
        ))->getIdempotencyKey();

        $this->assertEquals($expected, $claim->dispatch_key, 'the ledger must store the key the dispatched event declared');
    }

    /**
     * Nothing to send after all. The claim is released so the record is looked at again rather
     * than silently swallowed - a supplier attached tomorrow is still reminded.
     *
     * The counter is asserted as well as the empty table, because an empty table alone is what a
     * sweep that did NOTHING also leaves behind - the subject never selected, the offset never
     * matched, the registry empty - and the released counter is the only output that names which
     * branch ran. The empty table is still asserted: it is what kills a deleted release().
     *
     * The second sweep is what proves 'looked at again'. Nothing else in the suite demonstrates
     * that a released record is re-considered rather than merely un-recorded.
     */
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

    /**
     * A null anchor claims nothing at all. Claiming first and releasing after would be correct but
     * leaves a window in which a concurrent run sees the row and stands down, so nobody sends.
     *
     * Counter as well as row count, for the reason above: the empty table is equally satisfied by a
     * sweep in which the subject was never selected at all.
     */
    public function test_a_subject_whose_anchor_disappears_is_never_claimed()
    {
        config(['kompo-communications.reminders.scheduled' => [AnchorlessReminder::class]]);

        Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);

        $this->sweep()->expectsOutputToContain('0 to send, 1 without an anchor')->assertSuccessful();

        $this->assertEquals(0, DB::table('communication_reminder_claims')->count());
    }

    /**
     * A claim already held by another run - or orphaned by one that died mid-sweep - makes this run
     * stand down. Deleting the HELD_BY_ANOTHER_RUN arm sends a second copy under exactly the
     * concurrency the ledger exists to prevent, and every other test in the package stays green
     * while it does: they all reach a row that is either absent or already stamped.
     *
     * Seeded through the ledger rather than by running the sweep twice, because a second run cannot
     * reach this branch: the first run stamps dispatched_at, so the second reads ALREADY_HANDLED
     * and takes the other arm. An undispatched row is only ever left by a run still in flight or by
     * one that was killed, neither of which a test can stage from the command.
     */
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

    /**
     * A reminder that throws while building or dispatching its event keeps its claim, and is never
     * reconsidered. This is the package's one path to permanent, silent non-delivery, and it is a
     * deliberate inheritance from SISC: a send that fails afterwards is not retried, because a
     * reminder that goes out twice is worse than one that goes out once and fails visibly in the
     * log.
     *
     * Both directions of that line are reversible and both stay green without this test. Inverted
     * to a release(), a reminder that throws is re-attempted every hour for as long as it is
     * broken. Deleted, the throw reaches runReminder()'s per-offset catch, the offset counts as
     * broken rather than completed, and a single bad row turns the whole sweep's exit code red.
     *
     * The fixture is declared in this file rather than reused from a sibling test file: file-scope
     * test classes are not PSR-4 autoloadable, so a cross-file reference resolves only if the other
     * file happened to load first.
     */
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

    /**
     * The default policy reproduces SISC exactly: its unique index carries no date, so a moved
     * anchor never re-arms.
     */
    public function test_a_moved_anchor_does_not_re_arm_under_the_default_policy()
    {
        $subject = Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);

        Event::fake([DeadlineApproaching::class]);

        $this->sweep()->assertSuccessful();

        $subject->update(['due_on' => '2026-09-11']);

        // The counter, not just the count of events: one dispatch is equally what a second run that
        // selected NOTHING leaves behind - a broken update, a changed offset, a regressed date
        // clause - and 'already handled' is the only output saying the moved subject was
        // re-selected and then refused by the ledger.
        $this->sweep(['--date' => '2026-09-04'])
            ->expectsOutputToContain('0 to send, 1 already handled')
            ->assertSuccessful();

        $this->assertCount(1, $this->dispatchesFor($subject));
    }

    /** And PER_ANCHOR_DATE opts into the opposite: the new date is a new, legitimate claim. */
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

    /**
     * The email must state the anchor the recipient was selected for, not today's arithmetic. The
     * two diverge the moment a run straddles midnight.
     *
     * The divergence has to be STAGED, and MisreadAnchorReminder is what stages it. Under any
     * ordinary reminder the two readings are equal by construction - dueAt() selects on
     * whereDate(anchorExpression(), '=', targetDateFrom(today)), DeadlineReminder's expression is
     * due_on, and Deadline::reminderAnchorDate() parses the same due_on - so an event built from
     * targetDateFrom($run->today) instead of from the stored anchor prints exactly the same date
     * and no assertion here could tell. The fixture reads its anchor three days off its own
     * selection column, which is the shape of the disagreement AbstractDateAnchoredReminder's
     * anchorExpression() docblock warns about and cannot detect; the sweeper must carry whatever
     * anchorFor() answered, not re-derive it.
     *
     * The claim row is asserted alongside the event because the two must agree: the ledger records
     * the anchor a recipient was told, and a row stamped with the re-derived date would leave the
     * only forensic record of the send disagreeing with the send.
     *
     * DEVIATION from the plan text, which paired due_on 2026-09-04 with DeadlineReminder. That
     * reminder declares before(7) / onTheDay() / after(7), which from 2026-08-05 target
     * 2026-08-12, 2026-08-05 and 2026-07-29 — none of them 2026-09-04 — so NOTHING was dispatched,
     * ->first() answered null and null[0] was a fatal error rather than a failed assertion. The
     * expected days_left of '30' also required a before(30) that no registered reminder had.
     * Repaired by declaring the missing offset rather than by moving the subject onto 2026-08-12
     * and expecting '7': the point of the test is that the event states the STORED anchor.
     */
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

/** Always declines to send. */
class SilentReminder extends DeadlineReminder
{
    public function eventFor(Model $subject, ReminderOffset $offset, CarbonInterface $anchorOn, ReminderScope $scope): ?object
    {
        return null;
    }
}

/** Its subjects never have an anchor, however the query selected them. */
class AnchorlessReminder extends DeadlineReminder
{
    public function anchorFor(Model $subject): ?CarbonInterface
    {
        return null;
    }
}

/**
 * The PER_ANCHOR_DATE opt-in at sweep level.
 *
 * Duplicated from AnchorAndRearmTest's PerAnchorDeadlineReminder rather than reused: file-scope test
 * classes are not PSR-4 autoloadable, so a cross-file reference resolves only if the other test file
 * happened to load first. The twin proves the rearm KEY; this one proves what the sweeper does with
 * it, so the two must stay in step.
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
 *
 * The disagreement is the whole point: with any honest reminder the stored anchor and
 * targetDateFrom(today) are the same date, so nothing can distinguish an event built from the one
 * from an event built from the other. Overridden on the reminder rather than staged with data,
 * because test_deadlines carries a single date column - the same reason
 * SweeperDryRunTest's UnreadableAnchorReminder is fabricated that way.
 *
 * A single far-out offset, so the anchor and the day the sweep ran are also a month apart.
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

/** Cannot build its event at all, so the sweeper's dispatch catch is the only thing that runs. */
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
