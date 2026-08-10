<?php

namespace Condoedge\Communications\Reminders;

use Carbon\CarbonImmutable;

/**
 * One moment on a reminder's timeline.
 *
 * storageKey() is what lands in the unique index and it is NOT the display label: a label may be
 * translated, reworded or shown to an admin, and any of those would silently re-arm every reminder
 * already sent. 'b7' / 'd0' / 'a30' are three ASCII characters that no one will ever be tempted to
 * "improve".
 */
final class ReminderOffset
{
    private function __construct(
        public readonly ReminderPhase $phase,
        public readonly int $days,
    ) {
    }

    public static function before(int $days): self
    {
        if ($days < 1) {
            // onTheDay() is a different offset with a different storage key. Aliasing them here
            // would make before(0) and onTheDay() interchangeable at the API and distinct in the
            // index — the exact shape of the after(0) bug this type exists to kill.
            throw new \InvalidArgumentException('before() needs at least 1 day; use onTheDay().');
        }

        return new self(ReminderPhase::BEFORE, $days);
    }

    public static function onTheDay(): self
    {
        return new self(ReminderPhase::ON, 0);
    }

    public static function after(int $days): self
    {
        if ($days < 1) {
            // after(0) is the bug this class was written to kill: SISC's ReminderMilestone::after(0)
            // built daysBefore = 0, isAfterTheDeadline() read the sign and said false, so the
            // after-milestone dispatched the before-event under label 'j+0' — a second identity for
            // the calendar day 'j-0' already owned. Relaxing this to `< 0` re-creates it exactly.
            throw new \InvalidArgumentException('after() needs at least 1 day; use onTheDay().');
        }

        return new self(ReminderPhase::AFTER, $days);
    }

    /**
     * Hostile input (a config file, an admin typing into a day-offsets field) is normalised here,
     * not thrown at. A 0 in a list of offsets is a human meaning "on the day"; a 0 passed to
     * after() in code is a programmer error. Different inputs, different handling.
     */
    public static function fromParameterValue(ReminderPhase $phase, int $days): self
    {
        if ($days === PHP_INT_MIN) {
            // abs(PHP_INT_MIN) has no int representation, so PHP returns a float and the named
            // constructor below dies with a TypeError raised from a method the host never called —
            // uncatchable by the InvalidArgumentException handler this method's contract implies.
            throw new \InvalidArgumentException('Day offset out of range.');
        }

        $days = abs($days);

        return match (true) {
            $days === 0 => self::onTheDay(),
            // ON declares "the anchor day itself", so it carries no day count. Falling through to
            // before() would answer a declared phase with a different one — SISC's sign-inference
            // bug relocated from the sign to the parser — writing 'b5' into the claims unique index
            // under a phase the host never asked for.
            $phase === ReminderPhase::ON => self::onTheDay(),
            $phase === ReminderPhase::AFTER => self::after($days),
            default => self::before($days),
        };
    }

    /** Positive ahead of the anchor, negative past it. */
    public function signedDays(): int
    {
        return match ($this->phase) {
            ReminderPhase::BEFORE => $this->days,
            ReminderPhase::ON     => 0,
            ReminderPhase::AFTER  => -$this->days,
        };
    }

    /** The anchor value a subject must carry today for this offset to fire. */
    public function targetDateFrom(CarbonImmutable $today): CarbonImmutable
    {
        return $today->addDays($this->signedDays());
    }

    /** Permanent. Part of the unique index. Never localise, never rename. */
    public function storageKey(): string
    {
        return $this->phase->value . $this->days;
    }

    public function isAfterTheAnchor(): bool
    {
        return $this->phase === ReminderPhase::AFTER;
    }

    public function daysLeft(): int
    {
        return $this->phase === ReminderPhase::BEFORE ? $this->days : 0;
    }

    public function daysOverdue(): int
    {
        return $this->phase === ReminderPhase::AFTER ? $this->days : 0;
    }
}
