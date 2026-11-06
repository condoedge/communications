<?php

namespace Condoedge\Communications\Tests\Feature\Reminders;

use Condoedge\Communications\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The unique index is the whole mechanism. Application code never decides whether a reminder was
 * already sent — this index does, which is the only thing that holds when two runs race.
 */
class ClaimsTableTest extends TestCase
{
    public function test_the_table_exists_with_every_column_the_ledger_writes()
    {
        $this->assertTrue(Schema::hasTable('communication_reminder_claims'));

        foreach ([
            'reminder_key',
            'offset_key',
            'rearm_key',
            'subject_type',
            'subject_id',
            'anchor_on',
            'dispatch_key',
            'team_id',
            'claimed_at',
            'dispatched_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('communication_reminder_claims', $column),
                "missing column {$column}",
            );
        }
    }

    public function test_the_five_column_unique_index_exists()
    {
        $rows = collect(DB::select('SHOW INDEX FROM communication_reminder_claims'))
            ->where('Key_name', 'communication_reminder_claims_once')
            ->sortBy('Seq_in_index')
            ->values();

        $this->assertEquals(
            ['reminder_key', 'offset_key', 'rearm_key', 'subject_type', 'subject_id'],
            $rows->pluck('Column_name')->all(),
        );

        $this->assertEquals([0], $rows->pluck('Non_unique')->map(fn ($n) => (int) $n)->unique()->values()->all());
    }

    public function test_the_rearm_key_is_not_nullable()
    {
        $column = collect(DB::select('SHOW COLUMNS FROM communication_reminder_claims'))
            ->firstWhere('Field', 'rearm_key');

        $this->assertEquals('NO', $column->Null);
    }
}
