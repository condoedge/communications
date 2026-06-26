<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('communication_sending_recipients')) {
            return;
        }

        // Explicit short identifier names: the auto-generated
        // "communication_sending_recipients_*_foreign/index" names exceed MySQL's 64-char limit.
        Schema::create('communication_sending_recipients', function (Blueprint $table) {
            addMetaData($table);

            $table->foreignId('communication_sending_id')
                ->constrained('communication_sendings', 'id', 'csr_sending_fk')
                ->cascadeOnDelete();

            // Resolved Person recipient (when known) + ad-hoc morph (Recipient / User / ...).
            $table->foreignId('person_id')->nullable()
                ->constrained('persons', 'id', 'csr_person_fk')->nullOnDelete();
            $table->nullableMorphs('recipient', 'csr_recipient_idx');

            $table->string('email')->nullable();
            $table->foreignId('team_id')->nullable()
                ->constrained('teams', 'id', 'csr_team_fk')->nullOnDelete();

            $table->tinyInteger('status')->nullable();

            // Denormalized per-status timestamps -> every stat is a NOT NULL check, zero joins.
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('bounced_at')->nullable();

            $table->string('provider_message_id')->nullable();
            $table->string('provider_event')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_sending_recipients');
    }
};
