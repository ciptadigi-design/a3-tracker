<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_assisted_error_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('official_error_entry_id');
            $table->string('language', 10);
            $table->unsignedInteger('content_version');
            $table->string('status', 20);
            $table->char('source_normalized_digest', 64);
            $table->string('generator_provider', 80);
            $table->string('generator_model', 120);
            $table->string('generator_revision', 80)->nullable();
            $table->timestampTz('generated_at');
            $table->timestampTz('validated_at')->nullable();
            $table->text('classification_translation')->nullable();
            $table->text('classification_simplified')->nullable();
            $table->text('cause_translation')->nullable();
            $table->text('cause_simplified')->nullable();
            $table->text('warning_translation')->nullable();
            $table->text('warning_simplified')->nullable();
            $table->json('validation_errors')->nullable();
            $table->timestamps();

            $table->unique(['official_error_entry_id', 'language', 'content_version'], 'maee_official_language_version_uq');
            $table->index(['official_error_entry_id', 'language', 'status'], 'maee_official_language_status_idx');
            $table->foreign('official_error_entry_id', 'maee_official_entry_fk')->references('id')->on('maintenance_official_error_entries')->restrictOnDelete();
        });

        Schema::create('maintenance_assisted_error_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('assisted_error_entry_id');
            $table->uuid('official_step_id');
            $table->unsignedInteger('official_order');
            $table->text('translation');
            $table->text('simplified');
            $table->timestamps();

            $table->unique(['assisted_error_entry_id', 'official_step_id'], 'maes_assisted_official_step_uq');
            $table->unique(['assisted_error_entry_id', 'official_order'], 'maes_assisted_order_uq');
            $table->foreign('assisted_error_entry_id', 'maes_assisted_entry_fk')->references('id')->on('maintenance_assisted_error_entries')->cascadeOnDelete();
            $table->foreign('official_step_id', 'maes_official_step_fk')->references('id')->on('maintenance_official_error_steps')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_assisted_error_steps');
        Schema::dropIfExists('maintenance_assisted_error_entries');
    }
};
