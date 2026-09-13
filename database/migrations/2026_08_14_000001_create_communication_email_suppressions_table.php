<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_email_suppressions', function (Blueprint $table) {
            addMetaData($table);

            $table->string('email');

            // '' = every non-transactional category; reserved for per-category preferences later.
            // NOT NULL and defaulted on purpose: MySQL treats NULLs as distinct, so a nullable
            // category would make the unique index below silently accept duplicate rows.
            $table->string('category', 32)->default('');

            $table->string('reason', 32);
            $table->string('source', 64)->nullable();

            // The suppressed state IS unsubscribed_at being set — the row survives a resubscribe.
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamp('resubscribed_at')->nullable();

            $table->unique(['email', 'category'], 'communication_email_suppressions_email_category_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_email_suppressions');
    }
};
