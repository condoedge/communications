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
 * the offset - which is what sisc does, and which produces an email stating a deadline that is not
 * the deadline the recipient was selected for as soon as a run straddles midnight.
 */
class AnchorAndRearmTest extends TestCase
{
    public function test_the_anchor_is_read_off_the_subject()
    {
        $reminder = app(DeadlineReminder::class);
        $subject = Deadline::create(['due_on' => '2026-09-04', 'email' => 'a@b.test']);

        $this->assertEquals('2026-09-04', $reminder->anchorFor($subject)->toDateString());
    }

    public function test_a_subject_with_no_date_has_a_null_anchor()
    {
        $reminder = app(DeadlineReminder::class);
        $subject = Deadline::create(['due_on' => null, 'email' => 'a@b.test']);

        $this->assertNull($reminder->anchorFor($subject));
    }

    public function test_a_subject_that_does_not_implement_the_contract_has_a_null_anchor()
    {
        $reminder = app(DeadlineReminder::class);

        $this->assertNull($reminder->anchorFor(new AnchorlessSubject()));
    }

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

    public function test_a_per_anchor_policy_with_no_anchor_yields_a_non_null_sentinel()
    {
        $this->assertEquals('none', (new PerAnchorDeadlineReminder())->rearmKeyFor(null));
    }
}

/**
 * Never persisted; the table name only keeps Eloquent from inventing one that does not exist.
 */
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

/**
 * Named rather than anonymous because two tests share it; an anonymous class would be copied.
 */
class PerAnchorDeadlineReminder extends DeadlineReminder
{
    protected function rearmPolicy(): RearmPolicy
    {
        return RearmPolicy::PER_ANCHOR_DATE;
    }
}
