<?php

namespace Condoedge\Communications\Tests\Unit\Reminders;

use Carbon\CarbonImmutable;
use Condoedge\Communications\Reminders\ReminderOffset;
use Condoedge\Communications\Reminders\ReminderPhase;
use Condoedge\Communications\Tests\TestCase;

/**
 * The offset is the unit of a reminder's timeline, and its storageKey() is a DATA FORMAT: it lands
 * in the unique index of communication_reminder_claims, so changing one makes every reminder
 * already sent under the old key look unsent.
 *
 * The zero cases carry the weight here. SISC's predecessor inferred direction from the sign of an
 * int, so after(0) and before(0) were the same number with two different labels and the wrong
 * event. Both now throw, and onTheDay() is the only zero.
 */
class ReminderOffsetTest extends TestCase
{
    public function test_the_storage_keys_are_the_three_documented_forms()
    {
        $this->assertEquals('b7', ReminderOffset::before(7)->storageKey());
        $this->assertEquals('d0', ReminderOffset::onTheDay()->storageKey());
        $this->assertEquals('a30', ReminderOffset::after(30)->storageKey());
    }

    public function test_before_counts_forward_and_after_counts_back()
    {
        $this->assertEquals(7, ReminderOffset::before(7)->signedDays());
        $this->assertEquals(0, ReminderOffset::onTheDay()->signedDays());
        $this->assertEquals(-30, ReminderOffset::after(30)->signedDays());
    }

    public function test_the_target_date_is_today_plus_the_signed_offset()
    {
        $today = CarbonImmutable::parse('2026-08-05');

        $this->assertEquals('2026-08-12', ReminderOffset::before(7)->targetDateFrom($today)->toDateString());
        $this->assertEquals('2026-08-05', ReminderOffset::onTheDay()->targetDateFrom($today)->toDateString());
        $this->assertEquals('2026-07-06', ReminderOffset::after(30)->targetDateFrom($today)->toDateString());
    }

    /**
     * The bug this class was renamed to kill. Aliasing zero onto before/after would make
     * before(0) and onTheDay() interchangeable at the API and distinct in the index.
     */
    public function test_a_zero_offset_is_refused_by_before_and_after()
    {
        $this->expectException(\InvalidArgumentException::class);

        ReminderOffset::before(0);
    }

    public function test_a_zero_after_offset_is_refused_too()
    {
        $this->expectException(\InvalidArgumentException::class);

        ReminderOffset::after(0);
    }

    /**
     * Hostile input — a config file, an admin typing into a day-offsets field — is normalised
     * rather than thrown at. A 0 in a list of offsets is a human meaning "on the day"; a 0 passed
     * to after() in code is a programmer error. Different inputs, different handling.
     */
    public function test_a_zero_from_configuration_becomes_on_the_day_rather_than_throwing()
    {
        $offset = ReminderOffset::fromParameterValue(ReminderPhase::AFTER, 0);

        $this->assertEquals('d0', $offset->storageKey());

        // A declared ON phase carries no day count, so a stray number beside it must not be read as
        // a direction: answering 'b5' would file the claim under a phase the host never asked for.
        $this->assertEquals('d0', ReminderOffset::fromParameterValue(ReminderPhase::ON, 5)->storageKey());
    }

    public function test_a_negative_day_count_is_refused_by_the_named_constructors()
    {
        // Not a duplicate of the zero cases: zero is refused because onTheDay() owns it; a negative
        // is refused because 'b-5' and 'a5' would be two keys for one calendar day.
        $this->assertThrows(fn () => ReminderOffset::before(-5), \InvalidArgumentException::class);
        $this->assertThrows(fn () => ReminderOffset::after(-5), \InvalidArgumentException::class);
    }

    public function test_a_negative_number_from_configuration_is_read_through_its_declared_phase()
    {
        // The human wrote "-7" in an after-list. They meant 7 days after, not 7 days before.
        $this->assertEquals('a7', ReminderOffset::fromParameterValue(ReminderPhase::AFTER, -7)->storageKey());
        $this->assertEquals('b7', ReminderOffset::fromParameterValue(ReminderPhase::BEFORE, -7)->storageKey());
    }

    public function test_the_phase_is_answerable_without_inspecting_the_sign()
    {
        $this->assertTrue(ReminderOffset::after(1)->isAfterTheAnchor());
        $this->assertFalse(ReminderOffset::before(1)->isAfterTheAnchor());
        $this->assertFalse(ReminderOffset::onTheDay()->isAfterTheAnchor());
    }

    /** What the recipient is told. Zero on the wrong side of the anchor, never negative. */
    public function test_the_countdowns_are_one_sided()
    {
        $before = ReminderOffset::before(7);
        $after = ReminderOffset::after(30);

        $this->assertEquals(7, $before->daysLeft());
        $this->assertEquals(0, $before->daysOverdue());

        $this->assertEquals(0, $after->daysLeft());
        $this->assertEquals(30, $after->daysOverdue());
    }
}
