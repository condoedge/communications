<?php

namespace Condoedge\Communications\Tests\Feature\Reminders;

use Carbon\CarbonImmutable;
use Condoedge\Communications\Tests\Stubs\DeadlineReminder;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Nothing in sisc ever deleted a claim: one row per subject per offset, forever.
 */
class PruneCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-05 12:00:00');

        config([
            'kompo-communications.reminders.scheduled' => [DeadlineReminder::class],
            'kompo-communications.reminders.prune_after_days' => 400,
        ]);
    }

    protected function seedClaim(
        string $reminderKey,
        int $subjectId,
        string $claimedAt,
        bool $dispatched = true,
    ): void {
        DB::table('communication_reminder_claims')->insert([
            'reminder_key' => $reminderKey,
            'offset_key' => 'b7',
            'rearm_key' => 'once',
            'subject_type' => 'test',
            'subject_id' => $subjectId,
            'anchor_on' => null,
            'dispatch_key' => null,
            'team_id' => null,
            'claimed_at' => $claimedAt,
            'dispatched_at' => $dispatched ? $claimedAt : null,
        ]);
    }

    protected function assertTheWindowIsRefused(array $options, string $expectedInMessage): void
    {
        $this->seedClaim('test-deadline', 10, now()->subDays(500)->toDateTimeString());
        $this->seedClaim('test-deadline', 11, now()->subDays(1)->toDateTimeString());

        try {
            $this->artisan('communications:prune-reminder-claims', $options)->run();

            $this->fail('The command accepted a window it must refuse: ' . json_encode($options) . '.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($expectedInMessage, $e->getMessage());
        }

        $this->assertEquals(2, DB::table('communication_reminder_claims')->count());
    }

    public function test_claims_older_than_the_window_are_deleted()
    {
        $this->seedClaim('test-deadline', 1, now()->subDays(500)->toDateTimeString());
        $this->seedClaim('test-deadline', 2, now()->subDays(10)->toDateTimeString());

        $this->artisan('communications:prune-reminder-claims')->assertSuccessful();

        $this->assertEquals(0, DB::table('communication_reminder_claims')->where('subject_id', 1)->count());
        $this->assertEquals(1, DB::table('communication_reminder_claims')->where('subject_id', 2)->count());
    }

    public function test_claims_of_an_unregistered_reminder_are_left_alone()
    {
        $this->seedClaim('some-retired-reminder', 3, now()->subDays(500)->toDateTimeString());

        $this->artisan('communications:prune-reminder-claims')->assertSuccessful();

        $this->assertEquals(1, DB::table('communication_reminder_claims')->where('subject_id', 3)->count());
    }

    public function test_a_dry_run_deletes_nothing()
    {
        $this->seedClaim('test-deadline', 4, now()->subDays(500)->toDateTimeString());

        $this->artisan('communications:prune-reminder-claims', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertEquals(1, DB::table('communication_reminder_claims')->where('subject_id', 4)->count());
    }

    public function test_the_window_can_be_overridden()
    {
        $this->seedClaim('test-deadline', 5, now()->subDays(30)->toDateTimeString());

        $this->artisan('communications:prune-reminder-claims', ['--days' => 7])->assertSuccessful();

        $this->assertEquals(0, DB::table('communication_reminder_claims')->where('subject_id', 5)->count());
    }

    public function test_a_window_that_is_not_a_number_is_refused_rather_than_cast_to_a_cutoff_of_today()
    {
        $this->assertTheWindowIsRefused(['--days' => 'forever'], '--days');
    }

    public function test_a_window_of_zero_is_refused_rather_than_deleting_every_claim_there_is()
    {
        $this->assertTheWindowIsRefused(['--days' => 0], '--days');
    }

    public function test_a_negative_window_is_refused_rather_than_cutting_off_in_the_future()
    {
        $this->assertTheWindowIsRefused(['--days' => -30], '--days');
    }

    public function test_a_configured_window_that_is_not_a_number_is_refused_by_the_name_of_its_key()
    {
        config(['kompo-communications.reminders.prune_after_days' => 'forever']);

        $this->assertTheWindowIsRefused([], 'kompo-communications.reminders.prune_after_days');
    }

    public function test_a_configured_window_that_is_present_and_null_falls_back_to_the_package_default()
    {
        config(['kompo-communications.reminders.prune_after_days' => null]);

        $this->seedClaim('test-deadline', 6, now()->subDays(401)->toDateTimeString());
        $this->seedClaim('test-deadline', 7, now()->subDays(399)->toDateTimeString());

        $this->artisan('communications:prune-reminder-claims')->assertSuccessful();

        $this->assertEquals(0, DB::table('communication_reminder_claims')->where('subject_id', 6)->count());
        $this->assertEquals(1, DB::table('communication_reminder_claims')->where('subject_id', 7)->count());
    }

    public function test_a_dry_run_reports_exactly_what_the_real_run_then_deletes()
    {
        $this->seedClaim('test-deadline', 40, now()->subDays(500)->toDateTimeString());
        $this->seedClaim('test-deadline', 41, now()->subDays(500)->toDateTimeString(), dispatched: false);
        $this->seedClaim('test-deadline', 42, now()->subDays(7)->startOfDay()->toDateTimeString());
        $this->seedClaim('test-deadline', 43, now()->toDateTimeString());
        $this->seedClaim('some-retired-reminder', 44, now()->subDays(500)->toDateTimeString());

        $this->artisan('communications:prune-reminder-claims', ['--days' => 7, '--dry-run' => true])
            ->expectsOutputToContain('2 claim(s) would have been deleted')
            ->assertSuccessful();

        $this->artisan('communications:prune-reminder-claims', ['--days' => 7])
            ->expectsOutputToContain('2 claim(s) deleted')
            ->assertSuccessful();

        $this->assertEquals(
            [42, 43, 44],
            DB::table('communication_reminder_claims')->orderBy('subject_id')->pluck('subject_id')->all(),
        );
    }

    public function test_the_window_is_measured_in_whole_days_rather_than_from_the_current_clock()
    {
        CarbonImmutable::setTestNow('2026-08-05 12:00:00');

        $this->seedClaim('test-deadline', 8, '2026-07-29 06:00:00');

        $this->artisan('communications:prune-reminder-claims', ['--days' => 7])->assertSuccessful();

        $this->assertEquals(1, DB::table('communication_reminder_claims')->where('subject_id', 8)->count());
    }

    public function test_every_registered_reminder_is_pruned_and_the_total_is_their_sum()
    {
        config(['kompo-communications.reminders.scheduled' => [
            DeadlineReminder::class,
            SecondDeadlineReminder::class,
        ]]);

        $this->seedClaim('test-deadline', 50, now()->subDays(500)->toDateTimeString());
        $this->seedClaim('test-deadline-second', 51, now()->subDays(500)->toDateTimeString());

        $this->artisan('communications:prune-reminder-claims')
            ->expectsOutputToContain('2 claim(s) deleted')
            ->assertSuccessful();

        $this->assertEquals(0, DB::table('communication_reminder_claims')->count());
    }
}

/**
 * A second registered reminder, differing only in key().
 */
class SecondDeadlineReminder extends DeadlineReminder
{
    public function key(): string
    {
        return 'test-deadline-second';
    }
}
