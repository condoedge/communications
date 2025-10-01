<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_template_groups', function (Blueprint $table) {
            $table->boolean('direct_usage')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('communication_template_groups', function (Blueprint $table) {
            $table->dropColumn('direct_usage');
        });
    }
};
