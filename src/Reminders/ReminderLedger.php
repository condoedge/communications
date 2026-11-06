<?php

namespace Condoedge\Communications\Reminders;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Remembers which milestones have already been raised. The only writer of the claims table.
 */
class ReminderLedger
{
    public const TABLE = 'communication_reminder_claims';

    public function claim(
        string $reminderKey,
        ReminderOffset $offset,
        string $rearmKey,
        Model $subject,
        ?CarbonInterface $anchorOn,
        ?string $dispatchKey,
        ?int $teamId,
    ): ReminderClaim {
        try {
            DB::table(self::TABLE)->insert([
                'reminder_key'  => $reminderKey,
                'offset_key'    => $offset->storageKey(),
                'rearm_key'     => $rearmKey,
                'subject_type'  => $subject->getMorphClass(),
                'subject_id'    => $subject->getKey(),
                'anchor_on'     => $anchorOn?->toDateString(),
                'dispatch_key'  => $dispatchKey,
                'team_id'       => $teamId,
                'claimed_at'    => now(),
                'dispatched_at' => null,
            ]);

            return ReminderClaim::CLAIMED;
        } catch (UniqueConstraintViolationException) {
            return $this->stateOf($reminderKey, $offset, $rearmKey, $subject);
        } catch (QueryException $e) {
            $native = $e->errorInfo[1] ?? null;

            if ($native === 1062 || $e->getCode() === '23505') {
                return $this->stateOf($reminderKey, $offset, $rearmKey, $subject);
            }

            throw $e;
        }
    }

    protected function stateOf(
        string $reminderKey,
        ReminderOffset $offset,
        string $rearmKey,
        Model $subject,
    ): ReminderClaim {
        $dispatchedAt = $this->rowFor($reminderKey, $offset, $rearmKey, $subject)->value('dispatched_at');

        return $dispatchedAt === null ? ReminderClaim::HELD_BY_ANOTHER_RUN : ReminderClaim::ALREADY_HANDLED;
    }

    public function markDispatched(
        string $reminderKey,
        ReminderOffset $offset,
        string $rearmKey,
        Model $subject,
        ?string $dispatchKey = null,
    ): void {
        $update = ['dispatched_at' => now()];

        if ($dispatchKey !== null) {
            $update['dispatch_key'] = $dispatchKey;
        }

        $this->rowFor($reminderKey, $offset, $rearmKey, $subject)->update($update);
    }

    public function release(string $reminderKey, ReminderOffset $offset, string $rearmKey, Model $subject): void
    {
        $this->rowFor($reminderKey, $offset, $rearmKey, $subject)
            ->whereNull('dispatched_at')
            ->delete();
    }

    public function alreadyHandled(
        string $reminderKey,
        ReminderOffset $offset,
        string $rearmKey,
        Model $subject,
    ): bool {
        return $this->rowFor($reminderKey, $offset, $rearmKey, $subject)->exists();
    }

    public function prune(string $reminderKey, CarbonInterface $before, int $chunk = 1000): int
    {
        if ($chunk < 1) {
            throw new \InvalidArgumentException('prune() chunk must be at least 1.');
        }

        $deleted = 0;

        do {
            $round = $this->prunable($reminderKey, $before)->limit($chunk)->delete();

            $deleted += $round;
        } while ($round === $chunk);

        return $deleted;
    }

    public function prunableCount(string $reminderKey, CarbonInterface $before): int
    {
        return $this->prunable($reminderKey, $before)->count();
    }

    protected function prunable(string $reminderKey, CarbonInterface $before)
    {
        return DB::table(self::TABLE)
            ->where('reminder_key', $reminderKey)
            ->where('claimed_at', '<', $before);
    }

    protected function rowFor(string $reminderKey, ReminderOffset $offset, string $rearmKey, Model $subject)
    {
        return DB::table(self::TABLE)
            ->where('reminder_key', $reminderKey)
            ->where('offset_key', $offset->storageKey())
            ->where('rearm_key', $rearmKey)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey());
    }
}
