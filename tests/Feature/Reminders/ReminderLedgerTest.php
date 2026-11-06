<?php

namespace Condoedge\Communications\Tests\Feature\Reminders;

use Carbon\CarbonImmutable;
use Condoedge\Communications\Reminders\ReminderClaim;
use Condoedge\Communications\Reminders\ReminderLedger;
use Condoedge\Communications\Reminders\ReminderOffset;
use Condoedge\Communications\Tests\Stubs\Deadline;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The ledger is the only thing standing between a standing date predicate and a nightly re-send.
 * The query that selects a subject keeps matching for as long as the subject sits at that
 * milestone, so without a record of what already went out, a volunteer thirty days from expiry is
 * emailed every night for thirty nights.
 */
class ReminderLedgerTest extends TestCase
{
    protected ReminderLedger $ledger;
    protected Deadline $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = app(ReminderLedger::class);
        $this->subject = Deadline::create(['due_on' => '2026-08-12', 'email' => 'a@b.test']);
    }

    protected function claim(ReminderOffset $offset, string $rearmKey = 'once', ?Model $subject = null): ReminderClaim
    {
        return $this->ledger->claim(
            'test-reminder',
            $offset,
            $rearmKey,
            $subject ?? $this->subject,
            CarbonImmutable::parse('2026-08-12'),
            null,
            null,
        );
    }

    public function test_the_first_claim_succeeds()
    {
        $this->assertEquals(ReminderClaim::CLAIMED, $this->claim(ReminderOffset::before(7)));

        $this->assertDatabaseHas('communication_reminder_claims', [
            'reminder_key' => 'test-reminder',
            'offset_key' => 'b7',
            'rearm_key' => 'once',
            'subject_type' => $this->subject->getMorphClass(),
            'subject_id' => $this->subject->getKey(),
            'anchor_on' => '2026-08-12',
            'dispatched_at' => null,
        ]);
    }

    public function test_the_same_claim_cannot_be_taken_twice()
    {
        $this->claim(ReminderOffset::before(7));

        $this->assertNotEquals(ReminderClaim::CLAIMED, $this->claim(ReminderOffset::before(7)));

        $this->assertEquals(1, DB::table('communication_reminder_claims')->count());
    }

    public function test_an_undispatched_claim_reports_as_held_by_another_run()
    {
        $this->claim(ReminderOffset::before(7));

        $this->assertEquals(
            ReminderClaim::HELD_BY_ANOTHER_RUN,
            $this->claim(ReminderOffset::before(7)),
        );
    }

    public function test_a_dispatched_claim_reports_as_already_handled()
    {
        $offset = ReminderOffset::before(7);

        $this->claim($offset);
        $this->ledger->markDispatched('test-reminder', $offset, 'once', $this->subject, null);

        $this->assertEquals(ReminderClaim::ALREADY_HANDLED, $this->claim($offset));
    }

    public function test_a_later_offset_is_still_free_after_an_earlier_one_fired()
    {
        $this->assertEquals(ReminderClaim::CLAIMED, $this->claim(ReminderOffset::before(90)));
        $this->assertEquals(ReminderClaim::CLAIMED, $this->claim(ReminderOffset::before(30)));
    }

    public function test_a_different_rearm_key_is_a_different_claim()
    {
        $offset = ReminderOffset::before(7);

        $this->assertEquals(ReminderClaim::CLAIMED, $this->claim($offset, '2026-08-12'));
        $this->assertEquals(ReminderClaim::CLAIMED, $this->claim($offset, '2026-09-30'));
    }

    public function test_an_integrity_error_that_is_not_a_unique_violation_propagates()
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->ledger->claim(
            str_repeat('x', 300),
            ReminderOffset::before(7),
            'once',
            $this->subject,
            null,
            null,
            null,
        );
    }

    public function test_a_not_null_violation_inside_sqlstate_23000_still_propagates()
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->ledger->claim(
            'test-reminder',
            ReminderOffset::before(7),
            'once',
            new Deadline(),
            null,
            null,
            null,
        );
    }

    public function test_a_released_claim_can_be_taken_again()
    {
        $offset = ReminderOffset::before(7);

        $this->claim($offset);
        $this->ledger->release('test-reminder', $offset, 'once', $this->subject);

        $this->assertEquals(ReminderClaim::CLAIMED, $this->claim($offset));
    }

    public function test_a_dispatched_claim_cannot_be_released()
    {
        $offset = ReminderOffset::before(7);

        $this->claim($offset);
        $this->ledger->markDispatched('test-reminder', $offset, 'once', $this->subject, null);

        $this->ledger->release('test-reminder', $offset, 'once', $this->subject);

        $this->assertTrue($this->ledger->alreadyHandled('test-reminder', $offset, 'once', $this->subject));
        $this->assertEquals(ReminderClaim::ALREADY_HANDLED, $this->claim($offset));
    }

    public function test_the_dispatch_key_is_recorded_when_the_send_completes()
    {
        $offset = ReminderOffset::before(7);

        $this->claim($offset);
        $this->ledger->markDispatched('test-reminder', $offset, 'once', $this->subject, 'test-reminder:b7:2026-08-12');

        $this->assertDatabaseHas('communication_reminder_claims', [
            'reminder_key' => 'test-reminder',
            'offset_key' => 'b7',
            'dispatch_key' => 'test-reminder:b7:2026-08-12',
        ]);
    }

    public function test_a_later_dispatch_without_a_key_does_not_erase_the_recorded_one()
    {
        $offset = ReminderOffset::before(7);

        $this->claim($offset);
        $this->ledger->markDispatched('test-reminder', $offset, 'once', $this->subject, 'test-reminder:b7:2026-08-12');
        $this->ledger->markDispatched('test-reminder', $offset, 'once', $this->subject, null);

        $this->assertDatabaseHas('communication_reminder_claims', [
            'offset_key' => 'b7',
            'dispatch_key' => 'test-reminder:b7:2026-08-12',
        ]);
    }

    public function test_releasing_one_offset_leaves_another_offsets_claim_alone()
    {
        $this->claim(ReminderOffset::before(7));
        $this->claim(ReminderOffset::before(30));

        $this->ledger->release('test-reminder', ReminderOffset::before(7), 'once', $this->subject);

        $this->assertEquals(
            ['b30'],
            DB::table('communication_reminder_claims')->pluck('offset_key')->all(),
        );
    }

    public function test_dispatching_one_rearm_key_leaves_another_rearm_keys_claim_undispatched()
    {
        $offset = ReminderOffset::before(7);

        $this->claim($offset, '2026-08-12');
        $this->claim($offset, '2026-09-30');

        $this->ledger->markDispatched('test-reminder', $offset, '2026-08-12', $this->subject, null);

        $this->assertEquals(
            ['2026-08-12'],
            DB::table('communication_reminder_claims')
                ->whereNotNull('dispatched_at')
                ->pluck('rearm_key')
                ->all(),
        );
    }

    public function test_releasing_one_subjects_claim_leaves_another_subjects_claim_alone()
    {
        $other = Deadline::create(['due_on' => '2026-08-12', 'email' => 'c@d.test']);

        $this->claim(ReminderOffset::before(7));
        $this->claim(ReminderOffset::before(7), 'once', $other);

        $this->ledger->release('test-reminder', ReminderOffset::before(7), 'once', $this->subject);

        $this->assertEquals(
            [$other->getKey()],
            DB::table('communication_reminder_claims')
                ->pluck('subject_id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        );
    }
}
