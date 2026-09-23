<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_official_error_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('document_id');
            $table->string('code', 64);
            $table->string('variant_key', 160);
            $table->string('section_number', 80)->nullable();
            $table->text('classification')->nullable();
            $table->text('cause')->nullable();
            $table->text('alert_measure')->nullable();
            $table->text('correction')->nullable();
            $table->text('warning')->nullable();
            $table->text('note')->nullable();
            $table->text('isolation_dipsw')->nullable();
            $table->text('detached_control')->nullable();
            $table->unsignedInteger('source_page_start')->nullable();
            $table->unsignedInteger('source_page_end')->nullable();
            $table->longText('raw_source_text');
            $table->char('source_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'code', 'variant_key'], 'moee_document_code_variant_uq');
            $table->index('code', 'moee_code_idx');
            $table->index(['document_id', 'source_page_start'], 'moee_document_page_idx');
            $table->foreign('document_id', 'moee_document_fk')->references('id')->on('maintenance_documents')->restrictOnDelete();
        });

        Schema::create('maintenance_official_error_applicabilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('error_entry_id');
            $table->unsignedInteger('sequence');
            $table->string('scope_type', 40);
            $table->string('scope_label', 160);
            $table->uuid('machine_model_id')->nullable();
            $table->timestamps();

            $table->unique(['error_entry_id', 'sequence'], 'moea_entry_sequence_uq');
            $table->unique(['error_entry_id', 'scope_type', 'scope_label'], 'moea_entry_scope_uq');
            $table->index('machine_model_id', 'moea_machine_model_idx');
            $table->foreign('error_entry_id', 'moea_entry_fk')->references('id')->on('maintenance_official_error_entries')->cascadeOnDelete();
            $table->foreign('machine_model_id', 'moea_machine_model_fk')->references('id')->on('machine_models')->nullOnDelete();
        });

        Schema::create('maintenance_official_error_parts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('error_entry_id');
            $table->unsignedInteger('sequence')->nullable();
            $table->string('part_name', 200);
            $table->string('part_code', 100)->nullable();
            $table->string('applicability_label', 160)->nullable();
            $table->timestamps();

            $table->unique(['error_entry_id', 'sequence'], 'moep_entry_sequence_uq');
            $table->index(['error_entry_id', 'part_name'], 'moep_entry_name_idx');
            $table->foreign('error_entry_id', 'moep_entry_fk')->references('id')->on('maintenance_official_error_entries')->cascadeOnDelete();
        });

        Schema::create('maintenance_official_error_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('error_entry_id');
            $table->unsignedInteger('step_number');
            $table->text('instruction');
            $table->string('applicability_label', 160)->nullable();
            $table->boolean('requires_technician')->default(false);
            $table->timestamps();

            $table->unique(['error_entry_id', 'step_number'], 'moes_entry_step_uq');
            $table->foreign('error_entry_id', 'moes_entry_fk')->references('id')->on('maintenance_official_error_entries')->cascadeOnDelete();
        });

        Schema::create('maintenance_official_error_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('error_entry_id');
            $table->unsignedInteger('step_number')->nullable();
            $table->string('reference_type', 40);
            $table->text('reference_value');
            $table->unsignedInteger('page_number')->nullable();
            $table->string('section_number', 80)->nullable();
            $table->timestamps();

            $table->index(['error_entry_id', 'step_number'], 'moer_entry_step_idx');
            $table->index('reference_type', 'moer_type_idx');
            $table->foreign('error_entry_id', 'moer_entry_fk')->references('id')->on('maintenance_official_error_entries')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_official_error_references');
        Schema::dropIfExists('maintenance_official_error_steps');
        Schema::dropIfExists('maintenance_official_error_parts');
        Schema::dropIfExists('maintenance_official_error_applicabilities');
        Schema::dropIfExists('maintenance_official_error_entries');
    }
};
