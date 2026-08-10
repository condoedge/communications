<?php

namespace Condoedge\Communications\Tests\Feature\Reminders;

use Carbon\CarbonImmutable;
use Condoedge\Communications\Tests\Stubs\DeadlineReminder;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Nothing in SISC ever deleted a claim: one row per subject per offset, forever.
 *
 * The command is scoped to registered reminders on purpose. A blanket "delete everything older
 * than N" would also delete the claims of a reminder someone temporarily unregistered, and
 * re-registering it would then re-send its whole backlog.
 */
class PruneCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The clock is pinned for every test in this file, not just the ones that name a literal
        // date. Nine of them seed with now()->subDays(N) and then let the command call
        // CarbonImmutable::now() a second time; a run that crosses midnight between the insert and
        // the command moves the cutoff a whole day, and the dry-run/real-run test is the sharpest
        // case - its subject 42 sits exactly ON the cutoff and survives only the exclusive '<'
        // bound, so a crossing flips two pinned output strings and the surviving set at once. One
        // setTestNow() covers Carbon and CarbonImmutable both: Carbon's Test trait delegates to
        // FactoryImmutable::getDefaultInstance(), which the two share.
        //
        // No inline reset - Testbench's ApplicationTestingHooks::tearDown resets Carbon
        // unconditionally, and an inline reset is skipped exactly when a mid-method failure occurs.
        CarbonImmutable::setTestNow('2026-08-05 12:00:00');

        config([
            'kompo-communications.reminders.scheduled' => [DeadlineReminder::class],
            'kompo-communications.reminders.prune_after_days' => 400,
        ]);
    }

    /**
     * NOT named seed(): Laravel's InteractsWithDatabase already declares a PUBLIC seed(), so a
     * protected override is a fatal at class-load time - "Access level to seed() must be public" -
     * before a single test runs. Widening it to public would work and is worse: every call here
     * would then read as "run a database seeder" to anyone skimming.
     */
    protected function seedClaim(
        string $reminderKey,
        int $subjectId,
        string $claimedAt,
        // Two meanings only - delivered, or claimed by a run that died before delivering - so a
        // bool, not ReminderLedgerPruneTest's DISPATCHED_WITH_CLAIM sentinel. That sentinel exists
        // because its parameter carries a third meaning (a literal timestamp of its own) which
        // nothing here needs.
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

    /**
     * Both halves of a refusal: that it was refused BY NAME, and that nothing was deleted on the
     * way to refusing.
     *
     * Asserting only the exception passes against a guard placed after the delete loop - which is
     * the one arrangement that still empties the table before it complains. The recent row is what
     * catches it: the ledger's own prune() is scoped by reminder_key, so a cutoff of today (or of
     * a date in the future) takes the whole registered history and only a row that MUST survive
     * any legitimate window can see the difference.
     */
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

    /**
     * An unregistered reminder's claims are left alone. Deleting them and then re-registering the
     * reminder would re-send its entire backlog.
     */
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

    /**
     * A window that is not a number is REFUSED, not cast.
     *
     * (int) 'forever' is 0, and a window of 0 is not out of range in any way a later check could
     * see - it is a well-formed cutoff of today at midnight, which deletes essentially every claim
     * in the table. The next sweep then re-arms all of them and re-sends the entire backlog to
     * everyone. This is the same hole as SendScheduledRemindersCommand::hourFrom(), one blast
     * radius up: there the sweep goes silent, here it mails everybody.
     */
    public function test_a_window_that_is_not_a_number_is_refused_rather_than_cast_to_a_cutoff_of_today()
    {
        $this->assertTheWindowIsRefused(['--days' => 'forever'], '--days');
    }

    /**
     * Zero is refused as well, and it is the case a numeric check alone would let through.
     *
     * Refused rather than honoured as "prune everything now" - or gated behind a confirmation flag
     * - for two reasons the ledger states itself. prune()'s docblock deletes by AGE ALONE,
     * dispatched or not, and says $before MUST exceed the longest possible sweep or it deletes a
     * claim a live run is still holding and re-arms it, naming THIS command's window as where that
     * is enforced; a window of 0 is a cutoff of today, which is inside every possible sweep. And
     * the consequence of the mistake is not a lost report but a re-sent backlog, which cannot be
     * undone. An operator who genuinely means to empty the table can say so in SQL, where the
     * statement reads as the destructive act it is rather than as routine cron housekeeping with a
     * green exit code.
     */
    public function test_a_window_of_zero_is_refused_rather_than_deleting_every_claim_there_is()
    {
        $this->assertTheWindowIsRefused(['--days' => 0], '--days');
    }

    /**
     * Negative is worse than zero and reads as harmless.
     *
     * subDays(-30) is a cutoff THIRTY DAYS IN THE FUTURE, so `claimed_at < $before` matches every
     * row in the table including the claims taken minutes ago by a sweep still running. Nothing
     * downstream notices: prune() is given a perfectly valid CarbonInterface.
     */
    public function test_a_negative_window_is_refused_rather_than_cutting_off_in_the_future()
    {
        $this->assertTheWindowIsRefused(['--days' => -30], '--days');
    }

    /**
     * The configured window is read through the same refusal as --days, and the message names the
     * config key rather than the option, because that is where the operator has to go to fix it.
     */
    public function test_a_configured_window_that_is_not_a_number_is_refused_by_the_name_of_its_key()
    {
        config(['kompo-communications.reminders.prune_after_days' => 'forever']);

        $this->assertTheWindowIsRefused([], 'kompo-communications.reminders.prune_after_days');
    }

    /**
     * A configured window that is PRESENT and null lands on the package's own 400, exactly as
     * default_hour lands on nine - not on a cast to zero.
     *
     * config('...', 400) does not defend this: the second argument fires only on a MISSING key and
     * this package ships the key, so a host publishing the file as
     * `'prune_after_days' => env('COMMUNICATIONS_PRUNE_DAYS')` with the env unset arrives as null.
     * Cast, that is a cutoff of today on a command wired into cron. Asserted on the surviving row
     * rather than on an exception, because there is no exception - the wrong answer here is a
     * green run that emptied the table.
     */
    public function test_a_configured_window_that_is_present_and_null_falls_back_to_the_package_default()
    {
        config(['kompo-communications.reminders.prune_after_days' => null]);

        $this->seedClaim('test-deadline', 6, now()->subDays(401)->toDateTimeString());
        $this->seedClaim('test-deadline', 7, now()->subDays(399)->toDateTimeString());

        $this->artisan('communications:prune-reminder-claims')->assertSuccessful();

        $this->assertEquals(0, DB::table('communication_reminder_claims')->where('subject_id', 6)->count());
        $this->assertEquals(1, DB::table('communication_reminder_claims')->where('subject_id', 7)->count());
    }

    /**
     * The dry run and the real run must answer the same question, over fixtures chosen to span
     * every dimension the two could disagree on: an old delivered claim, an old claim that was
     * never dispatched (prune() deliberately does NOT filter on dispatched_at - see its docblock),
     * a claim sitting exactly ON the cutoff instant, a fresh one, and one belonging to a reminder
     * nobody registered.
     *
     * This exists because the two counts came from two hand-written copies of one predicate in two
     * files. Change '<' to '<=' in one, or add a filter to one, and the dry run starts lying about
     * what the real run will do - the single thing a dry run exists not to do, on the only
     * destructive command in the layer. The count is now built once, in ReminderLedger, and this
     * test is what stops it being copied back out.
     */
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

    /**
     * The window is a whole number of DAYS, measured from midnight - not a rolling interval
     * measured from the moment the command happens to start.
     *
     * Without startOfDay() the cutoff slides with the clock, so a row survives the 03:00 cron and
     * is deleted by a 23:00 re-run of the same command on the same day, with both runs printing
     * the same date on their first line. That makes the command non-reproducible within a day and
     * makes the printed date a lie, and it shortens the retention the ledger relies on: prune()'s
     * docblock requires $before to exceed the longest possible sweep, and midnight is the more
     * conservative of the two readings.
     *
     * No inline setTestNow() reset - Testbench's ApplicationTestingHooks::tearDown resets Carbon
     * unconditionally, and an inline reset is skipped exactly when a mid-method failure occurs.
     */
    public function test_the_window_is_measured_in_whole_days_rather_than_from_the_current_clock()
    {
        CarbonImmutable::setTestNow('2026-08-05 12:00:00');

        $this->seedClaim('test-deadline', 8, '2026-07-29 06:00:00');

        $this->artisan('communications:prune-reminder-claims', ['--days' => 7])->assertSuccessful();

        $this->assertEquals(1, DB::table('communication_reminder_claims')->where('subject_id', 8)->count());
    }

    /**
     * EVERY registered reminder is pruned, and the printed total is their SUM.
     *
     * Every other test in this file registers one reminder, and against a single registration the
     * loop and the running total are indistinguishable from pruning `all()->first()`: $total is
     * one reminder's count either way, so a `take(1)` slipped into the command survives the whole
     * file. A second key is the only fixture that can tell them apart - and the reminders that
     * would go unpruned are, by construction, the ones registered later, whose rows nobody is
     * watching yet.
     */
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
 *
 * Declared here rather than in tests/Stubs, the way SweeperDryRunTest declares BrokenReminder:
 * nothing outside this file needs it, and its whole purpose is to be the second entry in one
 * test's registry.
 */
class SecondDeadlineReminder extends DeadlineReminder
{
    public function key(): string
    {
        return 'test-deadline-second';
    }
}
