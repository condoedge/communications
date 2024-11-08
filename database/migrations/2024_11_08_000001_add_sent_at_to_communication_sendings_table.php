<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DIAGRAM: https://dbdiagram.io/d/6703f4b8fb079c7ebd9db055
     */
    public function up(): void
    {
        Schema::table('communication_sendings', function (Blueprint $table) {
            $table->timestamp('sent_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('communication_sendings', function (Blueprint $table) {
            $table->dropColumn('sent_at');
        });
    }
};
