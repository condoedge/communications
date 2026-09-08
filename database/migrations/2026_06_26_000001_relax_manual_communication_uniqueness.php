<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the (team_id, trigger) uniqueness: it forced one group per team per trigger, which
        // blocks a team from owning several manual communications (all trigger = ManualTrigger).
        // Internal-template "one per team per trigger" is still enforced in the app (the resolver's
        // ownGroupFor + copyForTeam guard).
        $existing = collect(DB::select('SHOW INDEX FROM communication_template_groups'))->pluck('Key_name');

        // The unique is the only index with team_id leftmost, so it backs the team_id foreign key —
        // MySQL refuses to drop it until another index covers team_id. Add a plain one first.
        if (!$existing->contains('ctg_team_id_idx')) {
            DB::statement('CREATE INDEX ctg_team_id_idx ON communication_template_groups (team_id)');
        }

        if ($existing->contains('ctg_team_trigger_unique')) {
            DB::statement('DROP INDEX ctg_team_trigger_unique ON communication_template_groups');
        }
    }

    public function down(): void
    {
        // Best-effort restore — fails if duplicate (team_id, trigger) rows now exist (several manual
        // comms for one team), which is exactly what this migration was relaxing.
        $existing = collect(DB::select('SHOW INDEX FROM communication_template_groups'))->pluck('Key_name');

        if (!$existing->contains('ctg_team_trigger_unique')) {
            DB::statement('CREATE UNIQUE INDEX ctg_team_trigger_unique ON communication_template_groups (team_id, `trigger`(191))');
        }

        // Drop the helper only after the unique (which also covers team_id) is back to back the FK.
        if ($existing->contains('ctg_team_id_idx')) {
            DB::statement('DROP INDEX ctg_team_id_idx ON communication_template_groups');
        }
    }
};
