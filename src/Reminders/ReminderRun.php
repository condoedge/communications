<?php

namespace Condoedge\Communications\Reminders;

use Carbon\CarbonImmutable;

/**
 * One sweep's frozen context.
 *
 * SISC read now() three independent times per subject — once in the runner, once inside every
 * dueAt(), and a third time inside BackgroundCheckExpiryReminder::eventFor() — and dueAt() never
 * received the runner's $today at all. A run that straddles midnight, or a long cursor iteration,
 * selects against one day and stamps another. One clock, threaded, closes that for good and makes
 * a frozen-time test a constructor argument rather than a Carbon::setTestNow() side effect.
 */
final class ReminderRun
{
    /** Always midnight. Declared rather than promoted so the constructor can normalise it. */
    public readonly CarbonImmutable $today;

    public function __construct(
        CarbonImmutable $today,
        public readonly int $hour,
        public readonly bool $dryRun = false,
    ) {
        if ($hour < 0 || $hour > 23) {
            // Range-checked HERE rather than at each caller, because the consumer is a strict
            // equality against ReminderScope::hour(), which that class's HH:MM guard already pins
            // to 0-23. An out-of-range run hour therefore matches NO scope of ANY reminder for the
            // whole run: the sweep does not fail, it does nothing at all, prints nothing at all and
            // exits 0 — indistinguishable from a quiet day, and unreported for as long as it lasts.
            // ReminderScope closed exactly this hole on the scope side and nothing closed it on the
            // run side, where `--hour=25` is one keystroke away. Placed in the constructor rather
            // than in the command so a hand-built backfill run is covered by the same guard; it
            // costs now() nothing, since format('G') cannot produce a value outside this range.
            throw new \InvalidArgumentException("A run hour must be a clock hour 0-23, got {$hour}.");
        }

        // startOfDay() here, not only in now(), because a caller that builds a run directly — a
        // backfill, a test — is otherwise the only thing standing between a residual time of day
        // and ReminderOffset::targetDateFrom(), whose addDays() carries that time straight into the
        // anchor. Today's one consumer survives it by calling toDateString(); the first
        // where(col, '=', $target) or diffInDays() written against this matches nothing at all,
        // and matches nothing quietly.
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
