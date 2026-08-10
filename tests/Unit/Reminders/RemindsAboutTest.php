<?php

namespace Condoedge\Communications\Tests\Unit\Reminders;

use Carbon\CarbonImmutable;
use Condoedge\Communications\Reminders\ReminderOffset;
use Condoedge\Communications\Tests\Stubs\Deadline;
use Condoedge\Communications\Tests\Stubs\DeadlineApproaching;
use Condoedge\Communications\Tests\TestCase;

/**
 * The two things a reminder event gets from the trait: an explicit idempotency key, and the three
 * template variables that were identical in all five of SISC's event classes.
 *
 * The key is NOT a replacement for the claim ledger. It guards a QUEUE RETRY, over ten minutes. The
 * ledger guards a STANDING DATE PREDICATE, forever. Conflating them is how someone deletes the
 * ledger and reintroduces nightly re-sends.
 *
 * The subject is the load-bearing component of the key. CommunicationTriggeredListener::
 * idempotencyKeyFor() folds the sorted recipient set into the IMPLICIT key and deliberately does not
 * fold it into the EXPLICIT one — an event that declares getIdempotencyKey() owns its identity
 * outright. A sweep is one dispatch per subject, all sharing a reminder key, an offset and an anchor
 * date, so a key without the subject makes the first send claim the 10-minute window and every later
 * one return early behind an info log. Nothing throws and nothing is retried.
 *
 * The three param NAMES and their STRING type are a data format owned by the HOST, not private
 * vocabulary of this layer: SISC registers 'deadline_date', 'days_left' and 'days_overdue' in
 * config/communication-variables.php and its already-authored templates interpolate them by name.
 * Renaming one, or letting a count become an int, leaves this suite green and renders live templates
 * wrong — which is why the names are asserted literally and the type is asserted separately.
 *
 * There is deliberately no second offset-magnitude comparison here. Not because it would duplicate
 * test_two_offsets_for_one_subject_get_different_keys — that one compares before(7) against
 * onTheDay(), which differ in BOTH components of storageKey(), so it alone survives a mutant that
 * drops $this->days and pins only the PHASE. The MAGNITUDE is pinned elsewhere, twice: literally in
 * tests/Unit/Reminders/ReminderOffsetTest.php:22-26 ('b7', 'd0', 'a30'), and again by the literal
 * 'b7' inside test_the_key_carries_reminder_offset_anchor_class_and_subject below. The axis is
 * closed; a third comparison would be the genuinely redundant one.
 */
class RemindsAboutTest extends TestCase
{
    /**
     * One helper, because the key tests and the param tests disagree about what varies: the key
     * tests hold the offset and anchor still and move the subject, the param tests hold the subject
     * still (a fixed 1) and move the offset and anchor. Two independent builders would each
     * hard-code the other's axis and drift the moment DeadlineApproaching's constructor changes.
     *
     * $id defaults to 1 rather than null because a null-keyed Deadline builds the subject-less key
     * this file's docblock calls catastrophic — the tests would be green while exercising the one
     * shape the trait exists to prevent.
     */
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

    /**
     * A named wrapper rather than inlining event() into the four key tests, because inlining would
     * put an offset and an anchor literal in every one of them and bury the single claim they make:
     * that ONLY the subject differs.
     */
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

    /** The same subject on the same offset and anchor is the same fire, which is the point. */
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

    /**
     * A moved deadline is a different message. The exact-shape test above cannot make this claim:
     * it pins one anchor, so it stays green if toDateString() is replaced by a constant equal to
     * that one anchor, and every reminder for every date would then share a key.
     */
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

    /**
     * Strings, not ints. assertEquals('30', 30) passes, so no value assertion above can make this
     * claim — an int slipping through renders as a bare number today and silently changes what
     * SISC's replacer callbacks receive.
     */
    public function test_the_counts_are_strings()
    {
        $params = $this->event(ReminderOffset::before(30))->getParams();

        $this->assertIsString($params['days_left']);
        $this->assertIsString($params['days_overdue']);
    }
}
