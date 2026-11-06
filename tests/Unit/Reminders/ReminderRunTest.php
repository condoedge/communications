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
 */
class ReminderRunTest extends TestCase
{
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

    public function test_the_day_and_hour_are_read_in_the_configured_timezone()
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-05 02:30:00', 'UTC'));
        Config::set('app.timezone', 'America/Toronto');

        $run = ReminderRun::now();

        $this->assertEquals('2026-08-04 00:00:00', $run->today->toDateTimeString());
        $this->assertEquals(22, $run->hour);
    }

    public function test_an_explicitly_passed_timezone_wins_over_the_configured_one()
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-05 02:30:00', 'UTC'));

        $run = ReminderRun::now(timezone: 'America/Toronto');

        $this->assertEquals('2026-08-04 00:00:00', $run->today->toDateTimeString());
        $this->assertEquals(22, $run->hour);
    }

    public function test_now_forwards_the_dry_run_flag()
    {
        $this->assertTrue(ReminderRun::now(dryRun: true)->dryRun);
    }

    public function test_the_hour_can_be_overridden_for_a_backfill()
    {
        CarbonImmutable::setTestNow('2026-08-05 14:32:00');

        $this->assertEquals(9, ReminderRun::now(hour: 9)->hour);

        $this->assertEquals(0, ReminderRun::now(hour: 0)->hour);

        CarbonImmutable::setTestNow();
    }

    public function test_a_run_hour_outside_the_clock_is_rejected_at_construction()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('0-23');

        new ReminderRun(CarbonImmutable::parse('2026-08-05'), 24);
    }

    public function test_a_baseline_scope_labels_itself_and_reads_its_hour_off_the_send_time()
    {
        $scope = new ReminderScope(null, [ReminderOffset::before(7)], '09:00');

        $this->assertNull($scope->teamId);
        $this->assertEquals('baseline', $scope->label());
        $this->assertEquals(9, $scope->hour());
        $this->assertCount(1, $scope->offsets);
        $this->assertSame([], $scope->teamIds);
    }

    public function test_a_team_scope_names_its_team()
    {
        $scope = new ReminderScope(42, [ReminderOffset::before(7)], '17:30', [42, 43]);

        $this->assertEquals('team 42', $scope->label());
        $this->assertEquals(17, $scope->hour());
        $this->assertEquals([42, 43], $scope->teamIds);
    }

    public function test_a_send_time_outside_the_clock_is_rejected()
    {
        $this->expectException(\InvalidArgumentException::class);

        new ReminderScope(null, [ReminderOffset::before(7)], '25:00');
    }

    public function test_an_unparseable_send_time_is_rejected()
    {
        $this->expectException(\InvalidArgumentException::class);

        new ReminderScope(null, [ReminderOffset::before(7)], 'noon');
    }
}
