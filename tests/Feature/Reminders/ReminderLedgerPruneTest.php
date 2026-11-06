<?php

namespace Condoedge\Communications\Tests\Feature\Reminders;

use Carbon\CarbonImmutable;
use Condoedge\Communications\Reminders\ReminderLedger;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Claims accumulate one row per subject per offset until they are pruned.
 */
class ReminderLedgerPruneTest extends TestCase
{
    protected const DISPATCHED_WITH_CLAIM = '__mirror_claimed_at__';

    protected ReminderLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = app(ReminderLedger::class);
    }

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

    public function test_pruning_one_reminder_leaves_the_others_alone()
    {
        $this->seedClaims('reminder-a', 4, '2024-01-01 00:00:00');
        $this->seedClaims('reminder-b', 4, '2024-01-01 00:00:00');

        $this->ledger->prune('reminder-a', CarbonImmutable::parse('2026-01-01'));

        $this->assertEquals(0, DB::table('communication_reminder_claims')->where('reminder_key', 'reminder-a')->count());
        $this->assertEquals(4, DB::table('communication_reminder_claims')->where('reminder_key', 'reminder-b')->count());
    }

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

    public function test_a_chunk_below_one_is_rejected_rather_than_looping_forever()
    {
        $this->seedClaims('guarded-reminder', 3, '2024-01-01 00:00:00');

        foreach ([0, -1] as $badChunk) {
            try {
                $this->ledger->prune('guarded-reminder', CarbonImmutable::parse('2026-01-01'), chunk: $badChunk);
                $this->fail("prune() accepted chunk {$badChunk}.");
            } catch (\InvalidArgumentException) {
            }
        }

        $this->assertEquals(3, DB::table('communication_reminder_claims')->count());
    }

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
