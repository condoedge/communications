<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_reminder_claims', function (Blueprint $table) {
            $table->id();

            $table->string('reminder_key', 100);

            $table->string('offset_key', 12);

            $table->string('rearm_key', 20);

            $table->string('subject_type', 255);
            $table->unsignedBigInteger('subject_id');

            $table->date('anchor_on')->nullable();

            $table->string('dispatch_key', 191)->nullable();

            $table->unsignedBigInteger('team_id')->nullable();

            $table->dateTime('claimed_at');

            $table->timestamp('dispatched_at')->nullable();

            $table->unique(
                ['reminder_key', 'offset_key', 'rearm_key', 'subject_type', 'subject_id'],
                'communication_reminder_claims_once',
            );

            $table->index(['subject_type', 'subject_id'], 'crc_subject_idx');

            $table->index(['reminder_key', 'claimed_at'], 'crc_prune_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_reminder_claims');
    }
};
