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
        Schema::create('communication_template_groups', function (Blueprint $table) {
            addMetaData($table);

            $table->string('title')->nullable();
            $table->string('trigger', 1000)->nullable();
        });

        Schema::create('communication_templates', function (Blueprint $table) {
            addMetaData($table);

            $table->tinyInteger('type')->nullable();
            $table->string('subject')->nullable();
            $table->longText('content')->nullable();
            $table->tinyInteger('is_draft')->nullable();
            $table->json('extra')->nullable();
            $table->foreignId('template_group_id')->nullable()->constrained('communication_template_groups');
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            addMetaData($table);

            $table->string('custom_button_text')->nullable();
            $table->string('custom_button_href')->nullable();
            $table->boolean('has_reminder_button')->nullable();
            $table->string('custom_button_handler')->nullable();
            $table->foreignId('communication_id')->nullable()->constrained('communication_templates');
        });

        Schema::create('communication_sendings', function (Blueprint $table) {
            addMetaData($table);

            $table->tinyInteger('status')->nullable();
            $table->foreignId('communication_template_id')->nullable()->constrained('communication_templates');
        });
        
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('notification_template_id')->nullable()->constrained('notification_templates');
            $table->string('trigger')->nullable();
            $table->string('custom_button_handler')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communication_template_groups');
        Schema::dropIfExists('communication_templates');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('communication_sendings');
    }
};
