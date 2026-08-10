<?php

namespace Condoedge\Communications\Tests\Feature\Reminders;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Condoedge\Communications\Reminders\ReminderLedger;
use Condoedge\Communications\Reminders\ReminderOffset;
use Condoedge\Communications\Reminders\ReminderRun;
use Condoedge\Communications\Reminders\ReminderScope;
use Condoedge\Communications\Tests\Stubs\Deadline;
use Condoedge\Communications\Tests\Stubs\DeadlineApproaching;
use Condoedge\Communications\Tests\Stubs\DeadlineReminder;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * A sweeper you cannot inspect before it emails fifty thousand people is one nobody will run.
 *
 * --dry-run must therefore be provably inert: no event, no claim row, no state of any kind. It
 * reads the ledger to report what is left to do, and writes nothing.
 */
class SweeperDryRunTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kompo-communications.reminders.scheduled' => [DeadlineReminder::class],
            'kompo-communications.reminders.default_hour' => 9,
        ]);
    }

    public function test_a_dry_run_dispatches_nothing_and_writes_nothing()
    {
        Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);

        Event::fake([DeadlineApproaching::class]);

        $this->artisan('communications:send-reminders', [
            '--dry-run' => true,
            '--date' => '2026-08-05',
            '--hour' => 9,
        ])->assertSuccessful();

        Event::assertNotDispatched(DeadlineApproaching::class);

        $this->assertEquals(0, DB::table('communication_reminder_claims')->count());
    }

    public function test_a_dry_run_reports_the_count_it_would_send()
    {
        Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);
        Deadline::create(['due_on' => '2026-08-12', 'email' => 'c@d.test']);

        $this->artisan('communications:send-reminders', [
            '--dry-run' => true,
            '--date' => '2026-08-05',
            '--hour' => 9,
        ])
            ->expectsOutputToContain('test-deadline')
            ->expectsOutputToContain('b7')
            ->assertSuccessful();
    }

    public function test_an_empty_registry_says_so_and_succeeds()
    {
        config(['kompo-communications.reminders.scheduled' => []]);

        $this->artisan('communications:send-reminders', ['--dry-run' => true])
            ->expectsOutputToContain('No reminder matched.')
            ->assertSuccessful();
    }

    public function test_the_only_option_filters_by_key_not_by_class_name()
    {
        Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);

        $this->artisan('communications:send-reminders', [
            '--dry-run' => true,
            '--only' => 'no-such-key',
            '--date' => '2026-08-05',
        ])
            ->expectsOutputToContain('No reminder matched.')
            ->assertSuccessful();
    }

    /**
     * The whole economics of an hourly cron. A scope scheduled for 09:00 must cost nothing at
     * 14:00 - not a subject query, not a ledger read.
     *
     * The header is asserted absent as well as the offset line, because the two fail differently.
     * Only the offset line proves the SUBJECT QUERY was skipped; only the header proves the
     * short circuit still stands AHEAD of the printing, rather than having been hoisted above it -
     * a reminder announced and then silently not swept is exactly the reading an operator
     * comparing a dry run against the old code must not be given.
     */
    public function test_a_scope_scheduled_for_another_hour_is_skipped_entirely()
    {
        Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);

        $this->artisan('communications:send-reminders', [
            '--dry-run' => true,
            '--date' => '2026-08-05',
            '--hour' => 14,
        ])
            ->doesntExpectOutputToContain('b7')
            ->doesntExpectOutputToContain('test-deadline')
            ->assertSuccessful();
    }

    /**
     * What a dry run is FOR: an operator re-running after a partial sweep must be told what the
     * ledger has already dealt with, not handed a to-send count that includes it.
     *
     * Without this, the dry-run branch could report every candidate as to-send and every assertion
     * above still passes - none of them seeds a claim. The port depends on this number: SISC's
     * behaviour is verified by comparing a dry run's counts against what the old code would have
     * sent, and a to-send figure that silently includes already-sent records makes that comparison
     * say the port is wrong when it is right.
     *
     * 'already CLAIMED', not 'already handled': the dry branch reads alreadyHandled(), which answers
     * whether a row EXISTS - a run still in flight as much as a completed one. The row seeded below
     * is undispatched and is precisely the first case, so the real run's wording would be a lie
     * about this very fixture. The number is the assertion; the word has to survive it.
     */
    public function test_a_dry_run_reports_what_the_ledger_has_already_handled()
    {
        $handled = Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);
        Deadline::create(['due_on' => '2026-08-12', 'email' => 'c@d.test']);

        app(ReminderLedger::class)->claim(
            'test-deadline',
            ReminderOffset::before(7),
            'once',
            $handled,
            CarbonImmutable::parse('2026-08-12'),
            null,
            null,
        );

        $this->artisan('communications:send-reminders', [
            '--dry-run' => true,
            '--date' => '2026-08-05',
            '--hour' => 9,
        ])
            ->expectsOutputToContain('1 to send, 1 already claimed')
            ->assertSuccessful();

        // Reading the ledger must not add to it. The seeded row is the only one there is.
        $this->assertEquals(1, DB::table('communication_reminder_claims')->count());
    }

    /**
     * A subject the reminder cannot read an anchor for is counted apart from the ones it would
     * send to, never folded into them.
     *
     * The state is unreachable through DeadlineReminder and is fabricated here for that reason: a
     * row with a null due_on can never satisfy whereDate('due_on', '=', ...), so the only subjects
     * that reach the null-anchor branch belong to a reminder whose anchorExpression() and whose
     * subject's reminderAnchorDate() read different things - the divergence
     * AbstractDateAnchoredReminder's docblock names and cannot detect. Left untested, dropping the
     * branch counts every one of those rows as to-send, and the dry run overstates the sweep by
     * exactly the number of records it is least able to send to.
     */
    public function test_a_subject_whose_anchor_cannot_be_read_is_counted_apart_from_the_sendable_ones()
    {
        config(['kompo-communications.reminders.scheduled' => [UnreadableAnchorReminder::class]]);

        Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);

        $this->artisan('communications:send-reminders', [
            '--dry-run' => true,
            '--date' => '2026-08-05',
            '--hour' => 9,
        ])
            ->expectsOutputToContain('0 to send, 1 without an anchor')
            ->assertSuccessful();
    }

    /**
     * A sweep in which NOTHING completed exits non-zero; a sweep in which something did exits zero
     * even though a reminder broke.
     *
     * Both halves in one test, because the ternary fails in both directions and each direction is
     * an operational decision. Always-SUCCESS means a wholly dead sweeper is invisible to cron
     * alerting for as long as it lasts. Always-FAILURE means one chronically broken reminder pages
     * someone every hour, which is how a real alert stops being read.
     */
    public function test_only_a_total_outage_exits_non_zero()
    {
        config(['kompo-communications.reminders.scheduled' => [BrokenReminder::class]]);

        $this->artisan('communications:send-reminders', [
            '--dry-run' => true,
            '--date' => '2026-08-05',
            '--hour' => 9,
        ])->assertFailed();

        config(['kompo-communications.reminders.scheduled' => [BrokenReminder::class, DeadlineReminder::class]]);

        $this->artisan('communications:send-reminders', [
            '--dry-run' => true,
            '--date' => '2026-08-05',
            '--hour' => 9,
        ])->assertSuccessful();
    }

    /**
     * The hour at which NOTHING is scheduled is not an outage — and this is the case a per-offset
     * count gets wrong on its own.
     *
     * At 14:00 the healthy reminder's only scope sweeps at 09:00, so the short circuit returns it
     * with zero offsets attempted and zero broken. The broken one throws out of scopes(), which
     * happens before any short circuit can apply. Gated on offsets alone, that is
     * `$completed === 0 && $broken > 0` — FAILURE — on 23 of every 24 runs of an hourly cron, for
     * an install that is behaving exactly as designed. An alert that fires 23 times a day is one
     * nobody reads on the day it means something, so this is worse than no alert at all.
     *
     * Pinned at an off-hour specifically: every other exit-code test here passes `--hour => 9`,
     * where the healthy reminder does attempt offsets, and all of them stay green under the bug.
     */
    public function test_a_broken_reminder_does_not_fail_the_run_at_an_hour_nothing_sweeps()
    {
        config(['kompo-communications.reminders.scheduled' => [BrokenReminder::class, DeadlineReminder::class]]);

        Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);

        $this->artisan('communications:send-reminders', [
            '--dry-run' => true,
            '--date' => '2026-08-05',
            '--hour' => 14,
        ])->assertSuccessful();
    }

    /**
     * The half a per-reminder counter cannot see, and the likelier outage of the two.
     *
     * A failure inside dueAt() - a dropped table, a DB outage, a host subjectQuery() naming a column
     * that no longer exists - is caught PER OFFSET and logged, so runReminder() returns normally
     * afterwards. Counted per reminder, control coming back is read as "this reminder completed",
     * every offset of every reminder can fail, and the sweeper still exits 0: a wholly dead sweeper
     * invisible to cron for as long as it lasts, which is exactly what the exit code exists to
     * prevent. Only a throw out of scopes() - what BrokenReminder above does - was ever counted.
     */
    public function test_a_reminder_that_fails_inside_every_offset_also_exits_non_zero()
    {
        config(['kompo-communications.reminders.scheduled' => [UnqueryableReminder::class]]);

        Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);

        $this->artisan('communications:send-reminders', [
            '--dry-run' => true,
            '--date' => '2026-08-05',
            '--hour' => 9,
        ])->assertFailed();
    }

    /**
     * --hour is rejected rather than cast, exactly as the config key it stands in for is.
     *
     * (int) 'noon' is 0, and 0 is a real hour: it produces a well-formed run that matches the
     * 00:00 scopes and nothing else, so the whole sweep relocates to midnight with no exception,
     * no log line and a green exit code. No range check downstream can catch it, because there is
     * nothing out of range about it.
     */
    public function test_an_hour_that_is_not_a_number_is_refused_rather_than_cast_to_midnight()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--hour');

        $this->artisan('communications:send-reminders', [
            '--dry-run' => true,
            '--hour' => 'noon',
        ])->run();
    }

    /**
     * The other half, and the one nothing else in the layer covers.
     *
     * ReminderScope's constructor refuses '25:00' on the scope side; the run side had no
     * equivalent, and the consumer is a strict equality between the two. An hour of 25 therefore
     * matches NO scope of ANY reminder for the entire run: the sweeper prints nothing, sends
     * nothing and exits 0, which reads as a quiet day rather than as a sweeper that has stopped.
     */
    public function test_an_hour_outside_the_clock_is_refused_rather_than_matching_no_scope_at_all()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('0-23');

        $this->artisan('communications:send-reminders', [
            '--dry-run' => true,
            '--hour' => 25,
        ])->run();
    }

    /**
     * A default_hour that is PRESENT and null must land on nine, the same as sendAt() lands on
     * nine - not on a cast to midnight.
     *
     * config('...', 9) does not defend this: the second argument fires only on a MISSING key, and
     * the package now ships the key, so `'default_hour' => env('COMMUNICATIONS_REMINDER_HOUR')`
     * with the env unset arrives as null. Cast, that is hour 0 while sendAt() still builds '09:00'
     * scopes - the run and the scopes disagree, nothing matches, and the sweep goes dark quietly.
     * Asserted on the offset line rather than on the exception, because there is no exception:
     * the wrong answer here is silence.
     */
    public function test_a_default_hour_that_is_present_and_null_sweeps_at_nine_like_the_scopes_do()
    {
        config(['kompo-communications.reminders.default_hour' => null]);

        Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);

        $this->artisan('communications:send-reminders', [
            '--dry-run' => true,
            '--date' => '2026-08-05',
        ])
            ->expectsOutputToContain('b7')
            ->assertSuccessful();
    }

    /**
     * `--date=0` is PRESENT and falsy. Read with a truthiness test it is silently treated as no
     * date at all and the sweep runs against today - a backfill operator's typo pointed at the
     * wrong calendar day, and outside a dry run it writes claims against that day which no later
     * run will re-arm. Read with `!== null` the same input reaches Carbon, which refuses it by
     * name and aborts before a single reminder runs. Same rule as ReminderScope::label() and
     * ReminderRegistry::all().
     */
    public function test_a_falsy_but_present_date_is_refused_rather_than_read_as_no_date()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Could not parse '0'");

        $this->artisan('communications:send-reminders', [
            '--dry-run' => true,
            '--date' => '0',
        ])->run();
    }

    /**
     * The other falsy-but-present date, and the one `!== null` alone does NOT catch: Carbon refuses
     * '0' by name but answers TODAY for '', so the branch is entered and the run silently sweeps
     * today - at default_hour rather than at the wall-clock hour, because the backfill branch reads
     * the configured hour. That is strictly worse than the truthiness reading it was written to
     * prevent, and `--date=$BACKFILL_DATE` with the variable unset is the ordinary way to produce
     * it, more likely than the '0' typo above. Outside a dry run it writes claims against the wrong
     * day which no later run will re-arm.
     */
    public function test_a_blank_date_is_refused_rather_than_sweeping_today()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--date must be a Y-m-d date');

        $this->artisan('communications:send-reminders', [
            '--dry-run' => true,
            '--date' => '',
        ])->run();
    }
}

/**
 * A reminder whose subject query selects rows it cannot then read an anchor from.
 *
 * anchorFor() is overridden rather than the subject changed, because the divergence this stands in
 * for is between an anchorExpression() the database filters on and a reminderAnchorDate() PHP
 * reads - two readings of one date that the layer has no way to compare. test_deadlines carries
 * one date column, so the divergence cannot be staged with data alone.
 */
class UnreadableAnchorReminder extends DeadlineReminder
{
    public function key(): string
    {
        return 'test-deadline-unreadable-anchor';
    }

    public function anchorFor(Model $subject): ?CarbonInterface
    {
        return null;
    }
}

/** A reminder that fails before it selects anything, so the sweep completes nothing at all. */
class BrokenReminder extends DeadlineReminder
{
    public function key(): string
    {
        return 'test-deadline-broken';
    }

    public function scopes(ReminderRun $run): iterable
    {
        throw new \RuntimeException('This reminder is broken.');
    }
}

/**
 * A reminder whose scopes are fine and whose SELECT is not - the shape of a real outage.
 *
 * Thrown from dueAt() rather than scopes(), because that is where a dropped table, an exhausted
 * connection pool and a renamed column all surface, and it is the side of the per-offset catch that
 * nothing else reaches.
 */
class UnqueryableReminder extends DeadlineReminder
{
    public function key(): string
    {
        return 'test-deadline-unqueryable';
    }

    public function dueAt(ReminderScope $scope, ReminderOffset $offset, ReminderRun $run): iterable
    {
        throw new \RuntimeException('The subject query failed.');
    }
}
