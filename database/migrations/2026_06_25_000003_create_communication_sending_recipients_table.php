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

            // Abstract recipient morph (Person / User / Recipient / ...) — no CRM concern leaks in.
            $table->nullableMorphs('recipient', 'csr_recipient_idx');

            // Denormalized display label + email captured at send time: the log is a historical
            // snapshot, so it reads with zero relation loads and survives the recipient's deletion.
            $table->string('name')->nullable();
            $table->string('email')->nullable();

            // The teams a recipient is recorded against live in communication_sending_recipient_teams
            // (a send can appear in several teams, counted once) — see that table's migration.

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
