<?php

namespace Condoedge\Communications\Tests\Unit\Reminders;

use Carbon\CarbonImmutable;
use Condoedge\Communications\Reminders\ReminderOffset;
use Condoedge\Communications\Tests\Stubs\Deadline;
use Condoedge\Communications\Tests\Stubs\DeadlineApproaching;
use Condoedge\Communications\Tests\TestCase;

/**
 * What a reminder event gets from the trait: an explicit idempotency key and three template
 * variables.
 */
class RemindsAboutTest extends TestCase
{
    protected function event(
        ReminderOffset $offset,
        string $anchor = '2026-09-04',
        string|int $id = 1,
    ): DeadlineApproaching {
        return new DeadlineApproaching(
            new Deadline(['id' => $id, 'due_on' => $anchor]),
            $offset,
            CarbonImmutable::parse($anchor),
        );
    }

    protected function eventFor(int $id): DeadlineApproaching
    {
        return $this->event(ReminderOffset::before(7), '2026-08-12', $id);
    }

    public function test_two_subjects_on_the_same_offset_and_anchor_get_different_keys()
    {
        $this->assertNotEquals(
            $this->eventFor(1)->getIdempotencyKey(),
            $this->eventFor(2)->getIdempotencyKey(),
            'one sweep is N dispatches; a shared key drops N-1 of them silently',
        );
    }

    public function test_the_key_carries_reminder_offset_anchor_class_and_subject()
    {
        $this->assertEquals(
            'test-deadline:b7:2026-08-12:' . DeadlineApproaching::class . ':42',
            $this->eventFor(42)->getIdempotencyKey(),
        );
    }

    public function test_the_same_subject_offset_and_anchor_get_the_same_key()
    {
        $this->assertEquals(
            $this->eventFor(7)->getIdempotencyKey(),
            $this->eventFor(7)->getIdempotencyKey(),
        );
    }

    public function test_two_offsets_for_one_subject_get_different_keys()
    {
        $this->assertNotEquals(
            $this->event(ReminderOffset::before(7), '2026-08-12', 1)->getIdempotencyKey(),
            $this->event(ReminderOffset::onTheDay(), '2026-08-12', 1)->getIdempotencyKey(),
        );
    }

    public function test_two_anchors_produce_different_keys()
    {
        $this->assertNotEquals(
            $this->event(ReminderOffset::before(30), '2026-09-04', 1)->getIdempotencyKey(),
            $this->event(ReminderOffset::before(30), '2026-10-04', 1)->getIdempotencyKey(),
        );
    }

    public function test_a_before_offset_supplies_a_countdown_and_no_overdue_count()
    {
        $params = $this->event(ReminderOffset::before(30))->getParams();

        $this->assertEquals('2026-09-04', $params['deadline_date']);
        $this->assertEquals('30', $params['days_left']);
        $this->assertEquals('0', $params['days_overdue']);
    }

    public function test_an_after_offset_supplies_an_overdue_count_and_no_countdown()
    {
        $params = $this->event(ReminderOffset::after(7))->getParams();

        $this->assertEquals('0', $params['days_left']);
        $this->assertEquals('7', $params['days_overdue']);
    }

    public function test_the_counts_are_strings()
    {
        $params = $this->event(ReminderOffset::before(30))->getParams();

        $this->assertIsString($params['days_left']);
        $this->assertIsString($params['days_overdue']);
    }
}
