<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_recipients', function (Blueprint $table) {
            addMetaData($table);

            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('name')->nullable();
            $table->string('language')->nullable();

            $table->unique(['team_id', 'email'], 'communication_recipients_team_email_unique');
            $table->unique(['team_id', 'phone'], 'communication_recipients_team_phone_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_recipients');
    }
};
