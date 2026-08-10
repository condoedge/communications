<?php

namespace Condoedge\Communications\Tests\Feature\Reminders;

use Condoedge\Communications\Reminders\Contracts\ScheduledReminder;
use Condoedge\Communications\Reminders\ReminderRegistry;
use Condoedge\Communications\Tests\Stubs\DeadlineReminder;
use Condoedge\Communications\Tests\TestCase;

/**
 * Registering a reminder is adding a class to a config array. The registry is what stops that
 * being a way to break the sweep silently at 3am.
 *
 * The duplicate-key check earns its place: two reminders sharing a key() would read each other's
 * claim rows and both go quiet, and nothing else in the system would notice.
 */
class ReminderRegistryTest extends TestCase
{
    public function test_registered_classes_are_resolved_to_instances()
    {
        config(['kompo-communications.reminders.scheduled' => [DeadlineReminder::class]]);

        $all = app(ReminderRegistry::class)->all();

        $this->assertCount(1, $all);
        $this->assertInstanceOf(ScheduledReminder::class, $all->first());
        $this->assertEquals('test-deadline', $all->first()->key());
    }

    public function test_an_empty_registry_is_an_empty_collection_not_an_error()
    {
        config(['kompo-communications.reminders.scheduled' => []]);

        $this->assertTrue(app(ReminderRegistry::class)->all()->isEmpty());
    }

    public function test_the_only_filter_selects_by_reminder_key()
    {
        config(['kompo-communications.reminders.scheduled' => [DeadlineReminder::class]]);

        $registry = app(ReminderRegistry::class);

        $this->assertCount(1, $registry->all('test-deadline'));
        $this->assertCount(0, $registry->all('no-such-reminder'));
        $this->assertCount(0, $registry->all(''));
    }

    /**
     * A host that has registered nothing must boot and sweep empty.
     *
     * This is not a hypothetical, even though the reminders block now ships: mergeConfigFrom merges
     * one level deep, so a host that published this config file before the block existed replaces
     * the package's array wholesale and reaches the registry with a missing key. Every other test
     * here sets the key explicitly, which means without this one the absent-key path is never
     * executed at all — and validate() runs at boot, where a foreach over the missing key's null is
     * an error that takes the whole application down rather than a sweep that does nothing.
     */
    public function test_the_reminders_config_block_being_absent_is_not_an_error()
    {
        config(['kompo-communications.reminders' => null]);

        $registry = app(ReminderRegistry::class);

        $this->assertTrue($registry->all()->isEmpty());

        $registry->validate();
        $this->addToAssertionCount(1); // validate() returns void; not throwing is the outcome.
    }

    /**
     * The filtered result is a LIST, asserted on keys because nothing else here would notice.
     *
     * filter() preserves the position a reminder held in the config array, so without values() a
     * match on the second entry comes back as [1 => $reminder]. That passes every count- and
     * first()-based assertion above and only surfaces where a caller indexes the collection or
     * json-encodes it, at which point a list silently becomes an object. Pinned for the same reason
     * the offset list is pinned in DateAnchoredSelectionTest.
     */
    public function test_the_filtered_result_is_reindexed_as_a_list()
    {
        config(['kompo-communications.reminders.scheduled' => [
            DeadlineReminder::class,
            OtherKeyReminder::class,
        ]]);

        $filtered = app(ReminderRegistry::class)->all('other-deadline');

        $this->assertSame([0], $filtered->keys()->all());
    }

    /**
     * The accept path, asserted explicitly because every other validate() test here expects a
     * throw — so a guard read the wrong way round (every registration seen as a collision)
     * refuses every host at boot and this file stays green.
     */
    public function test_a_registry_of_distinct_keys_is_accepted()
    {
        config(['kompo-communications.reminders.scheduled' => [
            DeadlineReminder::class,
            OtherKeyReminder::class,
        ]]);

        app(ReminderRegistry::class)->validate();

        $this->addToAssertionCount(1); // validate() returns void; not throwing is the outcome.
    }

    public function test_a_missing_class_is_refused_by_name()
    {
        config(['kompo-communications.reminders.scheduled' => ['App\\Nope\\NotThere']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('App\Nope\NotThere');

        app(ReminderRegistry::class)->validate();
    }

    public function test_a_class_that_does_not_implement_the_contract_is_refused()
    {
        config(['kompo-communications.reminders.scheduled' => [\stdClass::class]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ScheduledReminder');

        app(ReminderRegistry::class)->validate();
    }

    public function test_two_reminders_sharing_a_key_are_refused()
    {
        config(['kompo-communications.reminders.scheduled' => [
            DeadlineReminder::class,
            SameKeyReminder::class,
        ]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('test-deadline');

        app(ReminderRegistry::class)->validate();
    }
}

class SameKeyReminder extends DeadlineReminder
{
    // Same key() as DeadlineReminder, inherited. That is the point.
}

class OtherKeyReminder extends DeadlineReminder
{
    // A second, distinct key, so a filter can drop the FIRST entry and leave a gap at index 0.
    public function key(): string
    {
        return 'other-deadline';
    }
}
