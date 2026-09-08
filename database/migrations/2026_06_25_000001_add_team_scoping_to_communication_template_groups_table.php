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
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->boolean('disabled')->nullable();

            $table->foreignId('source_group_id')->nullable()
                ->constrained('communication_template_groups')->nullOnDelete();
        });


        DB::statement('CREATE INDEX ctg_trigger_team_idx ON communication_template_groups (`trigger`(191), team_id)');

        DB::statement('CREATE UNIQUE INDEX ctg_team_trigger_unique ON communication_template_groups (team_id, `trigger`(191))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX ctg_trigger_team_idx ON communication_template_groups');

        DB::statement('DROP INDEX ctg_team_trigger_unique ON communication_template_groups');

        Schema::table('communication_template_groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_group_id');
            $table->dropColumn('disabled');
            $table->dropConstrainedForeignId('team_id');
        });
    }
};
