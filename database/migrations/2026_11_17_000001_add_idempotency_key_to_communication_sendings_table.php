<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('communication_sendings', 'idempotency_key')) {
            return;
        }

        // The key of the event a sending was made for, so the same event handled again continues
        // this sending. Unique per template: two runs of one key cannot both create a sending.
        Schema::table('communication_sendings', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable();
            $table->unique(['idempotency_key', 'communication_template_id'], 'cs_idempotency_key_template_unique');
        });
    }

    public function down(): void
    {
        Schema::table('communication_sendings', function (Blueprint $table) {
            $table->dropUnique('cs_idempotency_key_template_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
