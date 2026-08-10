<?php

namespace Condoedge\Communications\Reminders;

/**
 * One audience partition and the schedule that applies to it.
 *
 * teamId is null for the baseline scope — the schedule every team that owns no override inherits,
 * and today the only scope any reminder returns. teamIds is the resolved subtree a team-specific
 * scope covers; it stays empty until per-team schedules land.
 */
final class ReminderScope
{
    /**
     * @param  ReminderOffset[]  $offsets
     * @param  int[]             $teamIds
     */
    public function __construct(
        public readonly ?int $teamId,
        public readonly array $offsets,
        public readonly string $sendAt,
        public readonly array $teamIds = [],
    ) {
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $sendAt)) {
            // Validated here rather than left to hour()'s cast, because the cast answers every
            // malformed input silently and in one of two ways, both green: '25:00' casts to 25 and
            // 'noon' casts to 0. The consumer is a strict equality gate against a run hour that
            // format('G') can only ever produce in 0-23, so an out-of-range value matches NO run
            // hour for the life of the deployment — the scope goes dark with a zero exit code and
            // nothing to read — while an unparseable one silently reschedules the whole scope to
            // midnight. Neither is reachable only by hand: default_hour is interpolated into this
            // string, so a config typo is enough.
            throw new \InvalidArgumentException("sendAt must be HH:MM, got '{$sendAt}'.");
        }
    }

    public function hour(): int
    {
        return (int) explode(':', $this->sendAt)[0];
    }

    public function label(): string
    {
        // `!== null`, not truthiness: teamId is nullable and null alone means "no team", so a falsy
        // test folds team 0 into the baseline and hands it a schedule that is not its own.
        return $this->teamId !== null ? "team {$this->teamId}" : 'baseline';
    }
}
