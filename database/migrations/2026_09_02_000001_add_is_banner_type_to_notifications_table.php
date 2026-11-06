<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A banner notification is drawn full width above the page instead of as a card in the
 * notifications list. The column lives here, not in kompo/auth, because this package already
 * owns its own columns on `notifications` (notification_template_id, trigger,
 * custom_button_handler — see 2024_10_14_000001_create_communications_tables).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            // Defaulted, not nullable: every existing row and every writer outside this package
            // (Notification::notify, createCustom) stays a normal notification with no code change.
            $table->boolean('is_banner_type')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('is_banner_type');
        });
    }
};
