<?php

namespace Condoedge\Communications\Tests\Feature\Reminders;

use Condoedge\Communications\Tests\Stubs\DeadlineReminder;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;

/**
 * A reminder can be perfectly correct and still send nothing: it has to be registered, and the
 * command has to be on a schedule. Both have failed silently in this codebase before.
 */
class ReminderWiringTest extends TestCase
{
    public function test_both_commands_are_registered()
    {
        $commands = array_keys(\Illuminate\Support\Facades\Artisan::all());

        $this->assertContains('communications:send-reminders', $commands);
        $this->assertContains('communications:prune-reminder-claims', $commands);
    }

    public function test_the_sweeper_is_scheduled_hourly_on_one_server()
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'communications:send-reminders'));

        $this->assertCount(1, $events, 'the sweeper must be scheduled exactly once');
        $this->assertEquals('0 * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->onOneServer);
        $this->assertSame(55, $events->first()->expiresAt, 'must stay under the 60-minute cadence');
    }

    public function test_the_prune_command_is_scheduled_weekly()
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'prune-reminder-claims'));

        $this->assertCount(1, $events, 'the prune command must be scheduled exactly once');
        $this->assertEquals('0 3 * * 1', $events->first()->expression);
        $this->assertTrue($events->first()->onOneServer);
    }

    public function test_boot_validation_accepts_a_well_formed_registry()
    {
        config(['kompo-communications.reminders.scheduled' => [DeadlineReminder::class]]);

        app(\Condoedge\Communications\Reminders\ReminderRegistry::class)->validate();

        $this->addToAssertionCount(1);
    }

    /**
     * Goes through loadListeners() rather than calling verifyReminders() directly, so that deleting
     * the CALL SITE fails too. A test that invokes verifyReminders() by name stays green with the
     * line removed from loadListeners(), which is the half that actually runs at boot.
     */
    public function test_boot_refuses_a_registry_naming_a_class_that_does_not_exist()
    {
        config(['kompo-communications.reminders.scheduled' => ['Nope\\MissingReminder']]);

        $provider = new \Condoedge\Communications\CondoedgeCommunicationServiceProvider($this->app);
        $method = new \ReflectionMethod($provider, 'loadListeners');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Nope\\MissingReminder');

        $method->invoke($provider);
    }

    public function test_the_reminders_config_block_is_merged()
    {
        $this->assertIsArray(config('kompo-communications.reminders.scheduled'));
        $this->assertIsInt(config('kompo-communications.reminders.default_hour'));
        $this->assertIsInt(config('kompo-communications.reminders.prune_after_days'));
    }
}
