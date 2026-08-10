<?php

namespace Condoedge\Communications\Tests\Feature\Reminders;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Condoedge\Communications\Reminders\Contracts\HasReminderAnchor;
use Condoedge\Communications\Reminders\RearmPolicy;
use Condoedge\Communications\Tests\Stubs\Deadline;
use Condoedge\Communications\Tests\Stubs\DeadlineReminder;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

/**
 * The anchor is read off the subject, once, and passed down. Nothing re-derives it from now() plus
 * the offset - which is what SISC does, and which produces an email stating a deadline that is not
 * the deadline the recipient was selected for as soon as a run straddles midnight.
 *
 * The rearm key is what decides whether a moved anchor may legitimately produce a second reminder.
 * ONCE_EVER is the default because it reproduces SISC's behaviour exactly: its unique index has no
 * date in it.
 *
 * These two methods are the only part of the base class the selection tests never reach, so
 * gutting anchorFor() to `return null` or rearmKeyFor() to `return 'once'` left the rest of the
 * suite green. This file is what makes those two edits fail.
 *
 * Offset dedupe and ordering are deliberately NOT re-asserted here: they are covered once, by
 * DateAnchoredSelectionTest::test_the_baseline_scope_dedupes_its_offsets_and_orders_them_furthest_out_first,
 * which feeds the same multiset and asserts it more strictly. A copy here would be a second copy of
 * one assertion, free to drift from the first.
 */
class AnchorAndRearmTest extends TestCase
{
    public function test_the_anchor_is_read_off_the_subject()
    {
        $reminder = app(DeadlineReminder::class);
        $subject = Deadline::create(['due_on' => '2026-09-04', 'email' => 'a@b.test']);

        $this->assertEquals('2026-09-04', $reminder->anchorFor($subject)->toDateString());
    }

    /** A first-class answer, not an error: the column is genuinely empty right now. */
    public function test_a_subject_with_no_date_has_a_null_anchor()
    {
        $reminder = app(DeadlineReminder::class);
        $subject = Deadline::create(['due_on' => null, 'email' => 'a@b.test']);

        $this->assertNull($reminder->anchorFor($subject));
    }

    /**
     * Null rather than a throw, because at send time a missing method and an empty column are
     * indistinguishable from one call, and throwing would abort a whole sweep over one bad row.
     *
     * This pins a fail-OPEN that has no compensating control today: a reminder wired to a subject
     * class that never implements the contract answers null for EVERY row, sends nothing, and exits
     * green. Nothing at boot catches that — see anchorFor()'s docblock for why a boot check cannot
     * simply demand the contract. Green here is not evidence the wiring is right.
     */
    public function test_a_subject_that_does_not_implement_the_contract_has_a_null_anchor()
    {
        $reminder = app(DeadlineReminder::class);

        $this->assertNull($reminder->anchorFor(new AnchorlessSubject()));
    }

    /**
     * The reminder's OWN key reaches the subject.
     *
     * HasReminderAnchor takes a $reminderKey for one documented reason: one model anchors several
     * reminders off different columns. Nothing else in the suite can see that argument - the
     * Deadline stub ignores it, exactly as a host model with a single anchor column would - so
     * anchorFor() could pass '', a constant, or another reminder's key and every other assertion
     * here would still hold. On a host model that switches on the key, that is the wrong date on
     * the email with no error anywhere.
     *
     * Driven through a reminder whose key is NOT the string this file expects anywhere else, so the
     * constant mutant cannot coincide with the expectation and survive.
     */
    public function test_the_reminders_own_key_reaches_the_subject()
    {
        $subject = new KeyRecordingSubject();

        (new OtherKeyDeadlineReminder())->anchorFor($subject);

        $this->assertSame('other-deadline', $subject->receivedKey);
    }

    public function test_the_default_policy_yields_a_constant_rearm_key()
    {
        $reminder = app(DeadlineReminder::class);

        $this->assertEquals('once', $reminder->rearmKeyFor(CarbonImmutable::parse('2026-08-12')));
        $this->assertEquals('once', $reminder->rearmKeyFor(CarbonImmutable::parse('2027-01-01')));
        $this->assertEquals('once', $reminder->rearmKeyFor(null));
    }

    public function test_a_per_anchor_policy_yields_the_anchor_date()
    {
        $reminder = new PerAnchorDeadlineReminder();

        $this->assertEquals('2026-08-12', $reminder->rearmKeyFor(CarbonImmutable::parse('2026-08-12')));
        $this->assertEquals('2027-01-01', $reminder->rearmKeyFor(CarbonImmutable::parse('2027-01-01')));
    }

    /**
     * 'none' rather than NULL. MySQL treats NULLs in a unique index as distinct, so a null rearm
     * key would let the same claim be taken an unbounded number of times.
     */
    public function test_a_per_anchor_policy_with_no_anchor_yields_a_non_null_sentinel()
    {
        $this->assertEquals('none', (new PerAnchorDeadlineReminder())->rearmKeyFor(null));
    }
}

/** Never persisted; the table name only keeps Eloquent from inventing one that does not exist. */
class AnchorlessSubject extends Model
{
    protected $table = 'test_deadlines';
}

/**
 * Records the key it was asked about instead of answering with a date. A public typed property is
 * not routed through Model::__set, so this does not become an attribute and does not need a column.
 */
class KeyRecordingSubject extends Model implements HasReminderAnchor
{
    protected $table = 'test_deadlines';

    public ?string $receivedKey = null;

    public function reminderAnchorDate(string $reminderKey): ?CarbonInterface
    {
        $this->receivedKey = $reminderKey;

        return null;
    }
}

/**
 * A key that deliberately differs from DeadlineReminder's, so that hardcoding the stub's own key
 * inside anchorFor() — a mutant every other assertion in this file lets through — fails here.
 */
class OtherKeyDeadlineReminder extends DeadlineReminder
{
    public function key(): string
    {
        return 'other-deadline';
    }
}

/** Named rather than anonymous because two tests share it; an anonymous class would be copied. */
class PerAnchorDeadlineReminder extends DeadlineReminder
{
    protected function rearmPolicy(): RearmPolicy
    {
        return RearmPolicy::PER_ANCHOR_DATE;
    }
}
