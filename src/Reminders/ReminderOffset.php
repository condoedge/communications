<?php

namespace Condoedge\Communications\Reminders;

use Carbon\CarbonImmutable;

/**
 * One moment on a reminder's timeline.
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
            throw new \InvalidArgumentException('after() needs at least 1 day; use onTheDay().');
        }

        return new self(ReminderPhase::AFTER, $days);
    }

    public static function fromParameterValue(ReminderPhase $phase, int $days): self
    {
        if ($days === PHP_INT_MIN) {
            throw new \InvalidArgumentException('Day offset out of range.');
        }

        $days = abs($days);

        return match (true) {
            $days === 0 => self::onTheDay(),
            $phase === ReminderPhase::ON => self::onTheDay(),
            $phase === ReminderPhase::AFTER => self::after($days),
            default => self::before($days),
        };
    }

    public function signedDays(): int
    {
        return match ($this->phase) {
            ReminderPhase::BEFORE => $this->days,
            ReminderPhase::ON     => 0,
            ReminderPhase::AFTER  => -$this->days,
        };
    }

    public function targetDateFrom(CarbonImmutable $today): CarbonImmutable
    {
        return $today->addDays($this->signedDays());
    }

    /** Stored in the claims table: changing the format makes sent reminders look unsent. */
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
