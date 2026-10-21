<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('communication_sending_recipients', 'position')) {
            return;
        }

        // The recipient's position in its sending. Recipient rows are bulk-inserted, which returns no
        // ids, so the delivery report (keyed by position) is matched back to its rows through this.
        // Nullable: rows written before the bulk insert have none and never need one.
        Schema::table('communication_sending_recipients', function (Blueprint $table) {
            $table->unsignedInteger('position')->nullable();
            $table->index(['communication_sending_id', 'position'], 'csr_sending_position_idx');
        });
    }

    public function down(): void
    {
        Schema::table('communication_sending_recipients', function (Blueprint $table) {
            $table->dropIndex('csr_sending_position_idx');
            $table->dropColumn('position');
        });
    }
};
