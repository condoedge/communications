<?php

namespace Condoedge\Communications\Reminders;

/**
 * One audience partition and the schedule that applies to it.
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
            throw new \InvalidArgumentException("sendAt must be HH:MM, got '{$sendAt}'.");
        }
    }

    public function hour(): int
    {
        return (int) explode(':', $this->sendAt)[0];
    }

    public function label(): string
    {
        return $this->teamId !== null ? "team {$this->teamId}" : 'baseline';
    }
}
