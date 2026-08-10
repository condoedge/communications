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
 *
 * The claim is written BEFORE the event is dispatched and the unique index is what decides — not a
 * read-then-write, which two runs on two servers would both pass.
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

    /**
     * Without this the subject is emailed every night until the date passes. The second call must
     * lose on the index, not on a prior read.
     */
    public function test_the_same_claim_cannot_be_taken_twice()
    {
        $this->claim(ReminderOffset::before(7));

        $this->assertNotEquals(ReminderClaim::CLAIMED, $this->claim(ReminderOffset::before(7)));

        $this->assertEquals(1, DB::table('communication_reminder_claims')->count());
    }

    /**
     * A claim taken but not yet dispatched belongs to a run that is still working. Saying so is
     * how the "A claims, B stands down, A releases, nobody sends" interleaving stops being
     * invisible.
     */
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

    /** Claims are per-offset: firing the 90-day warning must not consume the 30-day one. */
    public function test_a_later_offset_is_still_free_after_an_earlier_one_fired()
    {
        $this->assertEquals(ReminderClaim::CLAIMED, $this->claim(ReminderOffset::before(90)));
        $this->assertEquals(ReminderClaim::CLAIMED, $this->claim(ReminderOffset::before(30)));
    }

    /** And per-rearm-key, which is what lets a moved anchor legitimately re-arm. */
    public function test_a_different_rearm_key_is_a_different_claim()
    {
        $offset = ReminderOffset::before(7);

        $this->assertEquals(ReminderClaim::CLAIMED, $this->claim($offset, '2026-08-12'));
        $this->assertEquals(ReminderClaim::CLAIMED, $this->claim($offset, '2026-09-30'));
    }

    /**
     * The single most dangerous line in the layer. SISC caught the whole SQLSTATE 23000 class,
     * which also covers NOT NULL and foreign-key violations — so a malformed insert became
     * permanent, silent non-delivery with a green exit code.
     */
    public function test_an_integrity_error_that_is_not_a_unique_violation_propagates()
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        // reminder_key is varchar(100) and NOT NULL; 300 characters overflows it. In MySQL's
        // strict mode that is a data error, not a unique violation, and must not be swallowed.
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

    /**
     * The test above is necessary and not sufficient, and that gap is the whole point of this one.
     *
     * MySQL reports the 300-character overflow as SQLSTATE 22001 — OUTSIDE the 23000 class SISC
     * caught. Re-introducing SISC's exact bug (catch QueryException, swallow every code '23000')
     * therefore leaves that test green; verified by mutating this file's implementation, all seven
     * passed. It pins "do not catch absolutely everything" while leaving the actual regression
     * free to come back.
     *
     * An unsaved subject closes it: getKey() is null, subject_id is NOT NULL, and MySQL answers
     * 'SQLSTATE[23000] ... 1048 Column subject_id cannot be null' — inside the class SISC
     * swallowed, and not a unique violation. It is also the realistic shape of the fault: a
     * caller handing the ledger a model it never persisted would otherwise be recorded as
     * "already sent" forever, with a green exit code.
     *
     * A foreign-key violation would pin the same boundary, but this table deliberately carries no
     * foreign keys — see the team_id comment on the migration.
     */
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

    /**
     * A slow run must not be able to delete a faster one's completed claim and re-arm a reminder
     * that already reached someone.
     */
    public function test_a_dispatched_claim_cannot_be_released()
    {
        $offset = ReminderOffset::before(7);

        $this->claim($offset);
        $this->ledger->markDispatched('test-reminder', $offset, 'once', $this->subject, null);

        $this->ledger->release('test-reminder', $offset, 'once', $this->subject);

        $this->assertTrue($this->ledger->alreadyHandled('test-reminder', $offset, 'once', $this->subject));
        $this->assertEquals(ReminderClaim::ALREADY_HANDLED, $this->claim($offset));
    }

    /**
     * The claim row is the only place a decision to remind can be joined to what was actually
     * delivered. The key is only knowable after the event exists, so it is written on
     * markDispatched, not on claim.
     */
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

    /**
     * The test above records a key on a claim that had none, which is the easy direction: it stays
     * green with markDispatched()'s null guard deleted entirely — verified by mutation. This one
     * is the direction that guard exists for.
     *
     * A second markDispatched() with no key is a retry, or a caller that dispatched something
     * unkeyed. Adding dispatch_key to the update unconditionally writes null over the key the
     * first call recorded, destroying the only link between the claim and the event that actually
     * reached someone — and leaving dispatched_at set, so nothing ever looks wrong. A null key
     * means "nothing to record", never "erase what is recorded".
     */
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

    /**
     * release() and markDispatched() both address their row through rowFor(), which restates the
     * unique index as five where-clauses because a query builder cannot reference an index. Drop
     * one and the read is WIDER than the index, so it matches a sibling claim too — release()
     * deletes a second offset that was never released, and it is then re-armed and re-sent.
     *
     * Before this test, no test in the class held two claims at once while calling release() or
     * markDispatched(), so a narrowed rowFor() still addressed the right row everywhere; verified
     * by mutation — deleting the offset_key clause left the whole suite green. Only a subject
     * holding two claims at once can see it.
     */
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

    /**
     * The same hazard on the write path and on the column the test above cannot see, since both
     * of its rows share a rearm_key. Two claims differing only in rearm_key are one subject at one
     * offset across a moved anchor: a rowFor() missing rearm_key stamps both dispatched, and the
     * re-armed reminder — the whole reason rearm keys exist — never goes out.
     */
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

    /**
     * The same hazard on the clause neither test above can see, since both of their pairs are one
     * subject holding two claims. Two subjects at the same milestone in one sweep is the ordinary
     * case — the nightly run over everyone thirty days from expiry — so a rowFor() missing
     * subject_id makes release() delete a second subject's live claim, and that subject is then
     * re-armed and re-sent.
     */
    public function test_releasing_one_subjects_claim_leaves_another_subjects_claim_alone()
    {
        $other = Deadline::create(['due_on' => '2026-08-12', 'email' => 'c@d.test']);

        $this->claim(ReminderOffset::before(7));
        $this->claim(ReminderOffset::before(7), 'once', $other);

        $this->ledger->release('test-reminder', ReminderOffset::before(7), 'once', $this->subject);

        $this->assertEquals(
            [$other->getKey()],
            // Cast rather than compared as-is: subject_id is unsignedBigInteger and the mysql
            // driver hands bigints back as strings, so the raw pluck is ['2'] and assertEquals
            // against [2] would fail on type for a reason that has nothing to do with rowFor().
            DB::table('communication_reminder_claims')
                ->pluck('subject_id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        );
    }
}
