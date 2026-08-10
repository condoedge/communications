<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A test-only subject for the reminders layer.
 *
 * Deliberately trivial: one nullable date column and one nullable email. The package's own tests
 * must not depend on a host's schema, and SISC's fixtures cannot be reused — its invoice tests
 * call MySQL stored functions shipped by condoedge/finance, and its background-check query joins
 * a SISC-only table.
 */
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
