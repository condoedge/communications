<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_deadlines', function (Blueprint $table) {
            $table->id();
            $table->date('due_on')->nullable();
            $table->string('email')->nullable();
            $table->unsignedBigInteger('team_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_deadlines');
    }
};
