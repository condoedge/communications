<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_preferences', function (Blueprint $table) {
            addMetaData($table);

            // Keyed by address like the suppression list: a choice follows the mailbox, whoever shares it.
            $table->string('email', 191);

            // What the choice covers: every notification, one group of triggers, or one trigger.
            $table->string('scope', 16);

            // The group value or the trigger FQCN; '' for the whole address. Never null: see the unique index.
            $table->string('key', 191)->default('');

            $table->boolean('enabled');

            $table->unique(['email', 'scope', 'key'], 'communication_preferences_email_scope_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_preferences');
    }
};
