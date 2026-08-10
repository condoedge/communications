<?php

namespace Condoedge\Communications\Tests\Feature\Reminders;

use Carbon\CarbonImmutable;
use Condoedge\Communications\Reminders\ReminderLedger;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Claims accumulate one row per subject per offset, forever, and nothing in SISC ever deleted one.
 *
 * The chunked delete is the interesting part: it must terminate. A test whose row count exceeds
 * one chunk is the only thing that catches a loop condition that never becomes false.
 */
class ReminderLedgerPruneTest extends TestCase
{
    /**
     * Sentinel for claimRow()'s $dispatchedAt, because that parameter has THREE meanings and a plain
     * `= null` default can only carry two: "unspecified, so mirror claimed_at", "explicitly null,
     * an undispatched claim", and a literal timestamp. Written as a sentinel rather than a second
     * boolean parameter so that the one caller who wants a genuinely undispatched row says
     * `dispatchedAt: null` and reads as what it is.
     */
    protected const DISPATCHED_WITH_CLAIM = '__mirror_claimed_at__';

    protected ReminderLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = app(ReminderLedger::class);
    }

    /**
     * The ten columns of a claim row, in ONE place. The migration's team_id note says that column is
     * "Deliberately NOT in the unique index ... yet", and ReminderLedger::rowFor() says that if
     * team_id ever joins the index it joins the reads and the insert in the same commit. With the
     * literal spelled out per helper, one of them gets missed and silently seeds rows that
     * communication_reminder_claims_once no longer rejects, so the collision this file relies on to
     * keep subject ids distinct stops happening and the tests pass for the wrong reason.
     */
    protected function claimRow(
        string $reminderKey,
        int $subjectId,
        string $claimedAt,
        ?string $dispatchedAt = self::DISPATCHED_WITH_CLAIM,
    ): array {
        return [
            'reminder_key' => $reminderKey,
            'offset_key' => 'b7',
            'rearm_key' => 'once',
            'subject_type' => 'test',
            'subject_id' => $subjectId,
            'anchor_on' => null,
            'dispatch_key' => null,
            'team_id' => null,
            'claimed_at' => $claimedAt,
            'dispatched_at' => $dispatchedAt === self::DISPATCHED_WITH_CLAIM ? $claimedAt : $dispatchedAt,
        ];
    }

    protected function seedClaims(string $reminderKey, int $count, string $claimedAt): void
    {
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = $this->claimRow($reminderKey, $i, $claimedAt);
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('communication_reminder_claims')->insert($chunk);
        }
    }

    /**
     * One row, one explicit subject id — the tests below mix ages under a SINGLE reminder key,
     * which seedClaims() cannot express because it always restarts subject_id at 1 and the second
     * call would collide with communication_reminder_claims_once.
     */
    protected function seedClaimAt(
        string $reminderKey,
        int $subjectId,
        string $claimedAt,
        ?string $dispatchedAt = self::DISPATCHED_WITH_CLAIM,
    ): void {
        DB::table('communication_reminder_claims')->insert(
            $this->claimRow($reminderKey, $subjectId, $claimedAt, $dispatchedAt),
        );
    }

    public function test_old_claims_are_deleted_and_recent_ones_are_kept()
    {
        $this->seedClaims('old-reminder', 5, '2024-01-01 00:00:00');
        $this->seedClaims('recent-reminder', 3, '2026-08-01 00:00:00');

        $deleted = $this->ledger->prune('old-reminder', CarbonImmutable::parse('2026-01-01'));

        $this->assertEquals(5, $deleted);
        $this->assertEquals(0, DB::table('communication_reminder_claims')->where('reminder_key', 'old-reminder')->count());
        $this->assertEquals(3, DB::table('communication_reminder_claims')->where('reminder_key', 'recent-reminder')->count());
    }

    /** Pruning is scoped to one reminder key. A shared cutoff must not take the others with it. */
    public function test_pruning_one_reminder_leaves_the_others_alone()
    {
        $this->seedClaims('reminder-a', 4, '2024-01-01 00:00:00');
        $this->seedClaims('reminder-b', 4, '2024-01-01 00:00:00');

        $this->ledger->prune('reminder-a', CarbonImmutable::parse('2026-01-01'));

        $this->assertEquals(0, DB::table('communication_reminder_claims')->where('reminder_key', 'reminder-a')->count());
        $this->assertEquals(4, DB::table('communication_reminder_claims')->where('reminder_key', 'reminder-b')->count());
    }

    /**
     * More rows than one chunk. If the loop's termination condition is wrong this test hangs
     * rather than fails, which is itself the signal.
     */
    public function test_the_chunked_delete_terminates_past_one_chunk()
    {
        $this->seedClaims('big-reminder', 25, '2024-01-01 00:00:00');

        $deleted = $this->ledger->prune('big-reminder', CarbonImmutable::parse('2026-01-01'), chunk: 10);

        $this->assertEquals(25, $deleted);
        $this->assertEquals(0, DB::table('communication_reminder_claims')->count());
    }

    public function test_pruning_nothing_is_not_an_error()
    {
        $this->assertEquals(0, $this->ledger->prune('never-used', CarbonImmutable::parse('2026-01-01')));
    }

    /**
     * Old and recent rows under the SAME reminder key, which is the only arrangement that can tell
     * the two WHERE clauses apart. Verified by mutation: with old rows under one key and recent
     * rows under another, deleting the claimed_at condition outright leaves every other test in
     * this file green — prune() would then delete a reminder's ENTIRE history on every run,
     * re-arming milestones that already reached people, and the suite would still pass.
     *
     * The row sitting exactly on the cutoff is here for '<' vs '<=': $before is an exclusive bound,
     * so a claim taken at the cutoff instant survives. Without this row the operator is free.
     *
     * Subject 5 is old but never dispatched, and it pins WHICH date column decides retention:
     * claimed_at, not dispatched_at. Every other row here has the two equal, a state production
     * cannot produce — claim() writes dispatched_at null and markDispatched() stamps it strictly
     * later — so without this row `where('dispatched_at', '<', $before)` passes the whole file.
     * That mutant is not cosmetic: it loses the crc_prune_idx covering index, and rows that were
     * claimed and never dispatched (a run that died mid-sweep) would then accumulate forever,
     * which is the unbounded growth prune() exists to stop. A claim that was never dispatched is
     * still old.
     */
    public function test_the_cutoff_is_read_within_one_reminder_key()
    {
        $this->seedClaimAt('mixed-reminder', 1, '2024-01-01 00:00:00');
        $this->seedClaimAt('mixed-reminder', 2, '2025-06-30 12:00:00');
        $this->seedClaimAt('mixed-reminder', 3, '2026-01-01 00:00:00');
        $this->seedClaimAt('mixed-reminder', 4, '2026-08-01 00:00:00');
        $this->seedClaimAt('mixed-reminder', 5, '2024-01-01 00:00:00', dispatchedAt: null);

        $deleted = $this->ledger->prune('mixed-reminder', CarbonImmutable::parse('2026-01-01'));

        $this->assertEquals(3, $deleted);
        $this->assertEquals(
            [3, 4],
            DB::table('communication_reminder_claims')->orderBy('subject_id')->pluck('subject_id')->all(),
        );
    }

    /**
     * A chunk size is configuration, so it fails closed and loudly at the edit rather than open at
     * run time. chunk 0 emits LIMIT 0: every round deletes nothing while rows still match, and
     * `$round === $chunk` is 0 === 0 forever — a cron that never returns and never logs. A negative
     * chunk is dropped by the builder entirely and takes the whole matching set in one statement,
     * which is the unbounded delete the chunking exists to prevent.
     */
    public function test_a_chunk_below_one_is_rejected_rather_than_looping_forever()
    {
        $this->seedClaims('guarded-reminder', 3, '2024-01-01 00:00:00');

        foreach ([0, -1] as $badChunk) {
            try {
                $this->ledger->prune('guarded-reminder', CarbonImmutable::parse('2026-01-01'), chunk: $badChunk);
                $this->fail("prune() accepted chunk {$badChunk}.");
            } catch (\InvalidArgumentException) {
                // expected
            }
        }

        // Nothing was deleted on the way to throwing.
        $this->assertEquals(3, DB::table('communication_reminder_claims')->count());
    }

    /**
     * The chunk bound is the point of the loop: one DELETE over 200 000 rows holds locks for the
     * length of the statement and stalls every writer behind it. Counted as "at least ceil(rows /
     * chunk) statements" rather than an exact number, because an implementation that spends one
     * extra empty round terminating is harmless and should not be red here. Verified by mutation:
     * dropping ->limit() leaves every other test in this file green, since a single unbounded
     * DELETE returns the same total.
     */
    public function test_the_delete_is_issued_in_more_than_one_round()
    {
        $this->seedClaims('counted-reminder', 25, '2024-01-01 00:00:00');

        $deletes = 0;
        DB::listen(function ($query) use (&$deletes) {
            if (str_starts_with(strtolower(ltrim($query->sql)), 'delete')) {
                $deletes++;
            }
        });

        $this->ledger->prune('counted-reminder', CarbonImmutable::parse('2026-01-01'), chunk: 10);

        $this->assertGreaterThanOrEqual(3, $deletes);
    }
}
