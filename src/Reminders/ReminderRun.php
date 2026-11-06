<?php

namespace Condoedge\Communications\Reminders;

use Carbon\CarbonImmutable;

/**
 * One sweep's frozen context.
 */
final class ReminderRun
{
    public readonly CarbonImmutable $today;

    public function __construct(
        CarbonImmutable $today,
        public readonly int $hour,
        public readonly bool $dryRun = false,
    ) {
        if ($hour < 0 || $hour > 23) {
            throw new \InvalidArgumentException("A run hour must be a clock hour 0-23, got {$hour}.");
        }

        $this->today = $today->startOfDay();
    }

    public static function now(bool $dryRun = false, ?string $timezone = null, ?int $hour = null): self
    {
        $tz = $timezone
            ?? config('kompo-communications.reminders.timezone')
            ?? config('app.timezone');

        $moment = CarbonImmutable::now($tz);

        return new self($moment->startOfDay(), $hour ?? (int) $moment->format('G'), $dryRun);
    }
}
