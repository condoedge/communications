<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('communication_sending_recipients', 'error_message')) {
            return;
        }

        // Per-recipient outcome detail. The sending-level error_message only holds the failure that
        // aborted the whole send; with per-recipient isolation each recipient can fail for its own
        // reason (bad address, opted out, no phone), and that reason has to survive next to the row.
        Schema::table('communication_sending_recipients', function (Blueprint $table) {
            $table->text('error_message')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('communication_sending_recipients', function (Blueprint $table) {
            $table->dropColumn('error_message');
        });
    }
};
