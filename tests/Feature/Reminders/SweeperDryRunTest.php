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

        $this->assertEquals(1, DB::table('communication_reminder_claims')->count());
    }

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

    public function test_an_hour_that_is_not_a_number_is_refused_rather_than_cast_to_midnight()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--hour');

        $this->artisan('communications:send-reminders', [
            '--dry-run' => true,
            '--hour' => 'noon',
        ])->run();
    }

    public function test_an_hour_outside_the_clock_is_refused_rather_than_matching_no_scope_at_all()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('0-23');

        $this->artisan('communications:send-reminders', [
            '--dry-run' => true,
            '--hour' => 25,
        ])->run();
    }

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

    public function test_a_falsy_but_present_date_is_refused_rather_than_read_as_no_date()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Could not parse '0'");

        $this->artisan('communications:send-reminders', [
            '--dry-run' => true,
            '--date' => '0',
        ])->run();
    }

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

/**
 * A reminder that fails before it selects anything, so the sweep completes nothing at all.
 */
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
 * A reminder whose scopes are fine and whose select is not - the shape of a real outage.
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
