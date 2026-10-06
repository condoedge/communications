<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('communication_sending_recipient_teams')) {
            return;
        }

        // The teams one recipient row is recorded against. A send relevant to several teams gets one
        // recipient row + one pivot row per team: per-team views see it in each team, while totals
        // count the recipient row once. Short identifier names: the auto-generated ones exceed 64 chars.
        Schema::create('communication_sending_recipient_teams', function (Blueprint $table) {
            $table->id();

            $table->foreignId('communication_sending_recipient_id')
                ->constrained('communication_sending_recipients', 'id', 'csrt_recipient_fk')
                ->cascadeOnDelete();

            $table->foreignId('team_id')
                ->constrained('teams', 'id', 'csrt_team_fk')
                ->cascadeOnDelete();

            $table->unique(['communication_sending_recipient_id', 'team_id'], 'csrt_recipient_team_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_sending_recipient_teams');
    }
};
