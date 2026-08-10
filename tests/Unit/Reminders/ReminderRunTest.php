<?php

namespace Condoedge\Communications\Tests\Unit\Reminders;

use Carbon\CarbonImmutable;
use Condoedge\Communications\Reminders\ReminderOffset;
use Condoedge\Communications\Reminders\ReminderRun;
use Condoedge\Communications\Reminders\ReminderScope;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Support\Facades\Config;

/**
 * One clock per sweep, read once.
 *
 * SISC read now() three separate times per subject and never passed the runner's today into the
 * subject query at all, so a run that straddled midnight selected against one day and stamped
 * another. Freezing it into a constructor argument also means a time-sensitive test needs no
 * global setTestNow.
 */
class ReminderRunTest extends TestCase
{
    /**
     * The clock is normalised by the CONSTRUCTOR, not only by now(): a residual time of day here
     * rides through ReminderOffset::targetDateFrom()'s addDays() into whatever a caller compares
     * the anchor against, and an equality comparison then matches nothing without saying so.
     */
    public function test_a_run_carries_a_start_of_day_clock_and_an_hour()
    {
        $run = new ReminderRun(CarbonImmutable::parse('2026-08-05 23:59:50'), 23);

        $this->assertEquals('2026-08-05 00:00:00', $run->today->toDateTimeString());
        $this->assertEquals(23, $run->hour);
        $this->assertFalse($run->dryRun);
    }

    public function test_now_freezes_the_current_day_and_hour()
    {
        CarbonImmutable::setTestNow('2026-08-05 14:32:00');

        $run = ReminderRun::now();

        $this->assertEquals('2026-08-05 00:00:00', $run->today->toDateTimeString());
        $this->assertEquals(14, $run->hour);

        CarbonImmutable::setTestNow();
    }

    /**
     * Without this, deleting the whole timezone lookup and leaving `$tz = $timezone` keeps the
     * suite green: Testbench boots with app.timezone = UTC and PHP's default zone is also UTC, so
     * a configured zone and no zone at all are indistinguishable in every other test here. A host
     * on America/Toronto would then sweep two hours into the wrong calendar day and stamp claims
     * under it — the midnight-straddle bug this class exists to close, relocated one level up.
     *
     * No inline setTestNow() reset, unlike the two tests around it: Testbench's
     * ApplicationTestingHooks::tearDown resets Carbon unconditionally, so an inline reset only
     * ever runs when it was not needed and is skipped exactly when a mid-method failure occurs.
     *
     * The frozen moment is pinned to UTC explicitly, because the assertions are about a
     * UTC-to-Toronto day rollback and a bare string is parsed in PHP's default zone. That zone is
     * Testbench's UTC default today and is declared in neither phpunit.xml nor testbench.yaml, so
     * adding an APP_TIMEZONE there later would move this instant and fail here — reading as a
     * ReminderRun defect rather than the harness change it is.
     */
    public function test_the_day_and_hour_are_read_in_the_configured_timezone()
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-05 02:30:00', 'UTC'));
        Config::set('app.timezone', 'America/Toronto');

        $run = ReminderRun::now();

        $this->assertEquals('2026-08-04 00:00:00', $run->today->toDateTimeString());
        $this->assertEquals(22, $run->hour);
    }

    /**
     * The explicit argument, pinned independently of app.timezone: without this, the first `??`
     * link in now() can be deleted with the suite still green, because every other test here
     * reaches the zone through config.
     */
    public function test_an_explicitly_passed_timezone_wins_over_the_configured_one()
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-05 02:30:00', 'UTC'));

        $run = ReminderRun::now(timezone: 'America/Toronto');

        $this->assertEquals('2026-08-04 00:00:00', $run->today->toDateTimeString());
        $this->assertEquals(22, $run->hour);
    }

    /**
     * dryRun is what the sweeper branches on to decide whether anything is actually sent, and the
     * only other assertion on it exercises the constructor's default. Without this one, replacing
     * now()'s forwarded $dryRun with a literal false fails nothing.
     */
    public function test_now_forwards_the_dry_run_flag()
    {
        $this->assertTrue(ReminderRun::now(dryRun: true)->dryRun);
    }

    public function test_the_hour_can_be_overridden_for_a_backfill()
    {
        CarbonImmutable::setTestNow('2026-08-05 14:32:00');

        $this->assertEquals(9, ReminderRun::now(hour: 9)->hour);

        // 0 is the operator test: it is a real hour on both sides of the sweeper's `!==` gate,
        // since sendAt '00:00' yields hour() === 0. Written as `?:` instead of `??`, the override
        // is discarded here and the wall-clock hour answers in its place.
        $this->assertEquals(0, ReminderRun::now(hour: 0)->hour);

        CarbonImmutable::setTestNow();
    }

    /**
     * Built DIRECTLY, not through the command: the guard sits in the constructor rather than in
     * SendScheduledRemindersCommand precisely so a hand-built run — a backfill script, a test — is
     * covered by it too, and that placement argument was the one thing nothing exercised. The CLI's
     * --hour=25 reaches the same throw, so without this the guard could be moved into the command
     * with the suite still green and the hand-built path re-opened.
     *
     * The symptom it prevents: the run hour is compared by strict equality against
     * ReminderScope::hour(), which is pinned to 0-23, so an out-of-range run matches NO scope of ANY
     * reminder — the sweep prints nothing, sends nothing and exits 0, reading as a quiet day.
     */
    public function test_a_run_hour_outside_the_clock_is_rejected_at_construction()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('0-23');

        new ReminderRun(CarbonImmutable::parse('2026-08-05'), 24);
    }

    /** A scope with no team is the baseline: the schedule every team inherits. */
    public function test_a_baseline_scope_labels_itself_and_reads_its_hour_off_the_send_time()
    {
        $scope = new ReminderScope(null, [ReminderOffset::before(7)], '09:00');

        $this->assertNull($scope->teamId);
        $this->assertEquals('baseline', $scope->label());
        $this->assertEquals(9, $scope->hour());
        $this->assertCount(1, $scope->offsets);
        // assertSame, not assertEquals: a non-empty default would pass a loose comparison against
        // [] for several plausible wrong values.
        $this->assertSame([], $scope->teamIds);
    }

    public function test_a_team_scope_names_its_team()
    {
        $scope = new ReminderScope(42, [ReminderOffset::before(7)], '17:30', [42, 43]);

        $this->assertEquals('team 42', $scope->label());
        $this->assertEquals(17, $scope->hour());
        $this->assertEquals([42, 43], $scope->teamIds);
    }

    /**
     * An hour outside 0-23 is the dangerous half of the malformed set: it parses, it is stable, and
     * it can never equal a run hour, so the scope stops firing for good while everything reports
     * success. default_hour is interpolated into sendAt, so a config typo alone reaches here.
     */
    public function test_a_send_time_outside_the_clock_is_rejected()
    {
        $this->expectException(\InvalidArgumentException::class);

        new ReminderScope(null, [ReminderOffset::before(7)], '25:00');
    }

    /** The other half: an unparseable time casts to 0 and silently moves the scope to midnight. */
    public function test_an_unparseable_send_time_is_rejected()
    {
        $this->expectException(\InvalidArgumentException::class);

        new ReminderScope(null, [ReminderOffset::before(7)], 'noon');
    }
}
