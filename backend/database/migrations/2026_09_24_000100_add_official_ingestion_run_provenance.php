<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_official_ingestion_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('document_id');
            $table->string('source_file_name', 255);
            $table->string('source_storage_disk', 80);
            $table->string('source_storage_path', 500);
            $table->char('source_pdf_sha256', 64);
            $table->string('parser_revision', 100);
            $table->string('extractor_revision', 100);
            $table->char('release_git_sha', 40);
            $table->string('contract_name', 120);
            $table->string('contract_version', 40);
            $table->char('dataset_digest', 64);
            $table->unsignedInteger('discovered_count');
            $table->unsignedInteger('pass_count');
            $table->unsignedInteger('warn_count');
            $table->unsignedInteger('fail_count');
            $table->unsignedInteger('eligible_count');
            $table->unsignedInteger('distinct_discovered_code_count');
            $table->unsignedInteger('distinct_eligible_code_count');
            $table->unsignedInteger('identity_collision_count')->default(0);
            $table->unsignedInteger('unresolved_applicability_count')->default(0);
            $table->unsignedInteger('unverified_boundary_count')->default(0);
            $table->unsignedInteger('created_count');
            $table->unsignedInteger('unchanged_count');
            $table->unsignedInteger('updated_count');
            $table->unsignedInteger('rejected_count');
            $table->unsignedInteger('persisted_parent_count');
            $table->unsignedInteger('persisted_applicability_count');
            $table->unsignedInteger('persisted_part_count');
            $table->unsignedInteger('persisted_step_count');
            $table->unsignedInteger('persisted_reference_count');
            $table->unsignedInteger('warning_entry_count');
            $table->unsignedInteger('dipsw_entry_count');
            $table->unsignedInteger('detached_control_entry_count');
            $table->string('status', 20);
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['document_id', 'contract_name', 'source_pdf_sha256', 'dataset_digest'],
                'moir_document_contract_source_dataset_uq'
            );
            $table->index(['document_id', 'status'], 'moir_document_status_idx');
            $table->foreign('document_id', 'moir_document_fk')->references('id')->on('maintenance_documents')->restrictOnDelete();
        });

        Schema::table('maintenance_official_error_entries', function (Blueprint $table) {
            $table->uuid('ingestion_run_id')->nullable()->after('document_id');
            $table->char('normalized_digest', 64)->nullable()->after('source_hash');
            $table->index('ingestion_run_id', 'moee_ingestion_run_idx');
            $table->foreign('ingestion_run_id', 'moee_ingestion_run_fk')
                ->references('id')->on('maintenance_official_ingestion_runs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_official_error_entries', function (Blueprint $table) {
            $table->dropForeign('moee_ingestion_run_fk');
            $table->dropIndex('moee_ingestion_run_idx');
            $table->dropColumn(['ingestion_run_id', 'normalized_digest']);
        });

        Schema::dropIfExists('maintenance_official_ingestion_runs');
    }
};
