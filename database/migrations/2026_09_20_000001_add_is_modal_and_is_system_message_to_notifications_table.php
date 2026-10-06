<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A modal notification opens in a modal instead of sitting in a list; a system message is one the
 * recipient has to acknowledge, so `seen_at` doubles as its read record. Same ownership reasoning
 * as is_banner_type (see 2026_09_02_000001).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            // Defaulted, not nullable: every existing row and outside writer stays a normal notification.
            $table->boolean('is_modal')->default(false);
            $table->boolean('is_system_message')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn(['is_modal', 'is_system_message']);
        });
    }
};
