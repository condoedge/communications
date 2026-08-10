<?php

namespace Condoedge\Communications\Reminders;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Remembers which milestones have already been raised. The only writer of the claims table.
 *
 * The claim is written BEFORE the event is dispatched and the unique index is what decides — not a
 * read-then-write, which two runs on two servers would both pass.
 *
 * Consequence, inherited deliberately from SISC: a send that fails afterwards is not retried by
 * the next run. A reminder that goes out twice is worse than one that goes out once and fails
 * visibly in the send log.
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
            // Laravel's own narrow exception. Everything else — NOT NULL, a foreign key, a value
            // too long for its column — is a real fault and MUST NOT be swallowed into "already
            // sent", which is what catching the whole 23000 SQLSTATE class does: it turns a
            // malformed insert into permanent, silent non-delivery with a green exit code.
            return $this->stateOf($reminderKey, $offset, $rearmKey, $subject);
        } catch (QueryException $e) {
            // Load-bearing, NOT dead code behind the catch above: composer.json floors
            // laravel/framework at >=5.6.0, where UniqueConstraintViolationException does not exist
            // and the catch above silently never matches, and Connection::isUniqueConstraintError()
            // returns false for any driver outside the four Laravel ships. On those hosts this is
            // the only thing between a duplicate and a fatal that aborts the whole sweep at the
            // first already-reminded subject. 1062 is MySQL's duplicate-entry errno; 23505 is
            // PostgreSQL's unique_violation.
            $native = $e->errorInfo[1] ?? null;

            if ($native === 1062 || $e->getCode() === '23505') {
                return $this->stateOf($reminderKey, $offset, $rearmKey, $subject);
            }

            throw $e;
        }
    }

    /**
     * Why the claim failed, not just that it did.
     */
    protected function stateOf(
        string $reminderKey,
        ReminderOffset $offset,
        string $rearmKey,
        Model $subject,
    ): ReminderClaim {
        $dispatchedAt = $this->rowFor($reminderKey, $offset, $rearmKey, $subject)->value('dispatched_at');

        return $dispatchedAt === null ? ReminderClaim::HELD_BY_ANOTHER_RUN : ReminderClaim::ALREADY_HANDLED;
    }

    /**
     * Stamps the claim as delivered and records what went out.
     *
     * $dispatchKey can only be known here: it comes off the event, which does not exist until
     * eventFor() has run, which happens after the claim is taken.
     */
    public function markDispatched(
        string $reminderKey,
        ReminderOffset $offset,
        string $rearmKey,
        Model $subject,
        ?string $dispatchKey = null,
    ): void {
        $update = ['dispatched_at' => now()];

        // A null $dispatchKey means "nothing to record", never "erase what is recorded": the key is
        // OMITTED from the update rather than written as null, because writing it would blank a key
        // an earlier call recorded and destroy the only forensic link to the event that actually
        // went out (see the column's comment on the migration). Spelled as a branch rather than
        // array_filter over both keys, because a filter is general over a pair where only
        // dispatch_key can ever be null — and the predicate would then be a `!== null` that reads
        // like array_filter's default falsy callback, which a maintainer can "simplify" to the
        // default with the suite still green and a legitimately falsy key silently dropped.
        if ($dispatchKey !== null) {
            $update['dispatch_key'] = $dispatchKey;
        }

        $this->rowFor($reminderKey, $offset, $rearmKey, $subject)->update($update);
    }

    /** Undoes a claim, for when the reminder decided not to send after all. */
    public function release(string $reminderKey, ReminderOffset $offset, string $rearmKey, Model $subject): void
    {
        // Only an undispatched row may be released. Without this guard a slow run could delete the
        // completed claim of a faster one and re-arm a reminder that already reached someone.
        $this->rowFor($reminderKey, $offset, $rearmKey, $subject)
            ->whereNull('dispatched_at')
            ->delete();
    }

    /**
     * Whether a row exists at all — NOT ReminderClaim::ALREADY_HANDLED, which additionally requires
     * dispatched_at. A claim held by a run still working is true here and HELD_BY_ANOTHER_RUN
     * there; treating them as the same is how the interleaving this enum exists to expose becomes
     * invisible again.
     */
    public function alreadyHandled(
        string $reminderKey,
        ReminderOffset $offset,
        string $rearmKey,
        Model $subject,
    ): bool {
        return $this->rowFor($reminderKey, $offset, $rearmKey, $subject)->exists();
    }

    /**
     * Deletes by age alone, dispatched or not — deliberately NOT release()'s
     * whereNull('dispatched_at'). Adding that guard here would make the one shape of row pruning
     * exists to remove immortal: an undispatched claim past the cutoff is a run that died
     * mid-sweep, and keeping it forever blocks its milestone silently. The price is that $before
     * MUST exceed the longest possible sweep, or this deletes a claim a live run is still holding
     * and re-arms it; the command's default window is where that is enforced.
     */
    public function prune(string $reminderKey, CarbonInterface $before, int $chunk = 1000): int
    {
        if ($chunk < 1) {
            // limit(0) emits LIMIT 0 — deletes nothing while rows still match, so the loop below
            // never exits; a negative chunk is dropped by the builder and deletes the whole
            // matching set in one statement. Both are silent.
            throw new \InvalidArgumentException('prune() chunk must be at least 1.');
        }

        $deleted = 0;

        do {
            $round = $this->prunable($reminderKey, $before)->limit($chunk)->delete();

            $deleted += $round;
        } while ($round === $chunk);

        return $deleted;
    }

    /**
     * How many rows prune() would delete — for a dry run, which must not answer this itself.
     *
     * The count lived at the caller first, as a hand-written copy of the WHERE clause below. Two
     * copies of one predicate in two files agree only until someone edits one of them: add a
     * filter to prune(), or move the bound to '<=', and the dry run silently starts reporting a
     * number the real run will not produce — the one thing a dry run exists not to do, on the only
     * destructive path in this layer. rowFor() is the same move for the four methods above it.
     *
     * A READ, so it does not weaken this class being the claims table's only WRITER.
     */
    public function prunableCount(string $reminderKey, CarbonInterface $before): int
    {
        return $this->prunable($reminderKey, $before)->count();
    }

    /**
     * The single definition of "old enough to delete". Why it reads claimed_at alone and never
     * dispatched_at is argued on prune() — that reasoning governs this method too, and a filter
     * added here changes what a dry run PROMISES as well as what a real run does.
     */
    protected function prunable(string $reminderKey, CarbonInterface $before)
    {
        return DB::table(self::TABLE)
            ->where('reminder_key', $reminderKey)
            // '<', not '<=': $before is an exclusive bound, so a claim taken at the cutoff instant
            // survives. Pinned by the row sitting exactly on the cutoff in ReminderLedgerPruneTest.
            ->where('claimed_at', '<', $before);
    }

    protected function rowFor(string $reminderKey, ReminderOffset $offset, string $rearmKey, Model $subject)
    {
        // These five columns ARE communication_reminder_claims_once, restated because a query
        // builder cannot reference an index. Dropping one narrows the read while the index stays
        // wide, so this addresses the WRONG ROW rather than no row, and markDispatched()/release()
        // then stamp or delete a different offset's claim. If team_id ever joins that index (see
        // the migration's team_id note) it joins here and the insert above in the same commit.
        return DB::table(self::TABLE)
            ->where('reminder_key', $reminderKey)
            ->where('offset_key', $offset->storageKey())
            ->where('rearm_key', $rearmKey)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey());
    }
}
