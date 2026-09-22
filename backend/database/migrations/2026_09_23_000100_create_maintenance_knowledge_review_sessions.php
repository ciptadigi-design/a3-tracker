<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_knowledge_review_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            // Denormalized from document_import.document.account_id at session-start time, the
            // same "analytics table carries its own account_id" pattern governance_audit_logs
            // uses - this table is operational analytics, not a domain child record, so it does
            // not follow maintenance_document_references' "inherit scope, no column" convention.
            $t->foreignUuid('account_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('document_import_id')->constrained('maintenance_document_imports')->restrictOnDelete();
            $t->string('code', 64);
            $t->foreignUuid('reviewer_user_id')->constrained('users')->restrictOnDelete();

            $t->timestampTz('started_at');
            $t->timestampTz('last_activity_at');
            $t->timestampTz('source_review_started_at')->nullable();
            $t->timestampTz('authoring_started_at')->nullable();
            $t->timestampTz('authoring_saved_at')->nullable();
            $t->timestampTz('completed_at')->nullable();

            $t->unsignedInteger('active_seconds')->default(0);
            $t->unsignedInteger('source_page_views')->default(0);
            $t->unsignedInteger('authoring_edits')->default(0);
            $t->unsignedInteger('validation_failures')->default(0);

            $t->string('current_stage', 30)->default('GROUP_OPENED');
            // Null while in progress; PUBLISHED or ABANDONED once completed_at is set.
            $t->string('outcome', 20)->nullable();
            // Client-supplied monotonic counter for heartbeat idempotency: a heartbeat whose
            // client_seq does not exceed this value is a duplicate/retry and is accepted as a
            // no-op rather than double-applying its deltas. Null until the first heartbeat.
            $t->unsignedInteger('last_client_seq')->nullable();

            $t->timestamps();

            // Fast "find my open session for this group" lookup - the resume path on every
            // group-detail open - and the benchmark summary's per-import scan.
            $t->index(['document_import_id', 'code', 'reviewer_user_id', 'completed_at'], 'review_sessions_resume_idx');
            $t->index(['document_import_id', 'completed_at'], 'review_sessions_benchmark_idx');
            $t->engine = 'InnoDB';
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_knowledge_review_sessions');
    }
};
