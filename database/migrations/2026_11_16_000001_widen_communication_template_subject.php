<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The subject holds every locale as one JSON value, which a 255-character column cuts.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            DB::statement('ALTER TABLE ' . DB::getTablePrefix() . 'communication_templates MODIFY subject TEXT NULL');
        }
    }

    public function down(): void
    {
        // Not reversed: a shorter column would cut the subjects written since.
    }
};
