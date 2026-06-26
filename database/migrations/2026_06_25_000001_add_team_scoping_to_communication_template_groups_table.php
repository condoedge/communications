<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_template_groups', function (Blueprint $table) {
            if (!Schema::hasColumn('communication_template_groups', 'team_id')) {
                // NULL = the single SYSTEM baseline group per trigger.
                $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            }

            if (!Schema::hasColumn('communication_template_groups', 'disabled')) {
                // Team owns this row purely to suppress its subtree.
                $table->boolean('disabled')->nullable();
            }

            if (!Schema::hasColumn('communication_template_groups', 'source_group_id')) {
                // Provenance of a copy & edit clone (reset / diff).
                $table->foreignId('source_group_id')->nullable()
                    ->constrained('communication_template_groups')->nullOnDelete();
            }
        });

        // trigger is string(1000); index/unique on the full column blows the InnoDB
        // key-length limit, so use a prefix. Triggers are class names << 191 chars.
        $existing = collect(DB::select('SHOW INDEX FROM communication_template_groups'))
            ->pluck('Key_name');

        if (!$existing->contains('ctg_trigger_team_idx')) {
            DB::statement('CREATE INDEX ctg_trigger_team_idx ON communication_template_groups (`trigger`(191), team_id)');
        }

        if (!$existing->contains('ctg_team_trigger_unique')) {
            DB::statement('CREATE UNIQUE INDEX ctg_team_trigger_unique ON communication_template_groups (team_id, `trigger`(191))');
        }
    }

    public function down(): void
    {
        $existing = collect(DB::select('SHOW INDEX FROM communication_template_groups'))
            ->pluck('Key_name');

        if ($existing->contains('ctg_trigger_team_idx')) {
            DB::statement('DROP INDEX ctg_trigger_team_idx ON communication_template_groups');
        }
        if ($existing->contains('ctg_team_trigger_unique')) {
            DB::statement('DROP INDEX ctg_team_trigger_unique ON communication_template_groups');
        }

        Schema::table('communication_template_groups', function (Blueprint $table) {
            if (Schema::hasColumn('communication_template_groups', 'source_group_id')) {
                $table->dropConstrainedForeignId('source_group_id');
            }
            if (Schema::hasColumn('communication_template_groups', 'disabled')) {
                $table->dropColumn('disabled');
            }
            if (Schema::hasColumn('communication_template_groups', 'team_id')) {
                $table->dropConstrainedForeignId('team_id');
            }
        });
    }
};
