<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_sendings', function (Blueprint $table) {
            if (!Schema::hasColumn('communication_sendings', 'team_id')) {
                $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            }
            if (!Schema::hasColumn('communication_sendings', 'trigger')) {
                $table->string('trigger')->nullable();
            }
            if (!Schema::hasColumn('communication_sendings', 'channel')) {
                // Denormalized CommunicationType.
                $table->tinyInteger('channel')->nullable();
            }
            if (!Schema::hasColumn('communication_sendings', 'recipients_count')) {
                $table->integer('recipients_count')->nullable();
            }
            if (!Schema::hasColumn('communication_sendings', 'error_message')) {
                $table->text('error_message')->nullable();
            }
            // sent_at already added by 2024_11_08_000001.
        });
    }

    public function down(): void
    {
        Schema::table('communication_sendings', function (Blueprint $table) {
            if (Schema::hasColumn('communication_sendings', 'error_message')) {
                $table->dropColumn('error_message');
            }
            if (Schema::hasColumn('communication_sendings', 'recipients_count')) {
                $table->dropColumn('recipients_count');
            }
            if (Schema::hasColumn('communication_sendings', 'channel')) {
                $table->dropColumn('channel');
            }
            if (Schema::hasColumn('communication_sendings', 'trigger')) {
                $table->dropColumn('trigger');
            }
            if (Schema::hasColumn('communication_sendings', 'team_id')) {
                $table->dropConstrainedForeignId('team_id');
            }
        });
    }
};
