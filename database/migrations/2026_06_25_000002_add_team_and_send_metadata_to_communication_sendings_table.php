<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_sendings', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->string('trigger')->nullable();
            $table->tinyInteger('channel')->nullable();
            $table->integer('recipients_count')->nullable();
            $table->text('error_message')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('communication_sendings', function (Blueprint $table) {
            $table->dropColumn('error_message');
            $table->dropColumn('recipients_count');
            $table->dropColumn('channel');
            $table->dropColumn('trigger');
            $table->dropConstrainedForeignId('team_id');
        });
    }
};
