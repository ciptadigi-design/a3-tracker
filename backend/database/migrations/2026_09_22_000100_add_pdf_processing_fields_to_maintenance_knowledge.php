<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V1.6 - Extracted Knowledge Processing. Purely additive: reuses the V1.3
 * maintenance_document_imports/maintenance_knowledge_entries lifecycle rather
 * than introducing a parallel table, per the same "extend, don't duplicate"
 * precedent as add_error_code_to_maintenance_document_extractions.
 *
 * maintenance_document_imports gains "which extraction (if any) this
 * processing run is derived from, and how far along it got" - a PDF_EXTRACTION
 * import represents one deterministic-detector processing run over one
 * extraction's pages, distinct from a MANUAL_ENTRY/BULK_IMPORT import (which
 * has no extraction_id and no processing_* timestamps).
 *
 * maintenance_knowledge_entries gains the provenance/evidence fields a
 * PDF-derived candidate needs that a manually-typed entry never did: which
 * extraction and page range it came from, its normalized code, and a
 * deterministic evidence/collision classification. The
 * maintenance_knowledge_entry_identity_uq index is the idempotency key
 * (Section I): re-running processing for the same extraction (same import
 * row - see MaintenanceKnowledgeProcessingService) upserts by
 * (import_id, normalized_code, source_page_start, source_page_end) instead
 * of creating duplicates. Manual entries never set normalized_code/page
 * range, and MySQL treats NULLs in a unique index as distinct - the same
 * accepted pattern machine_error_codes' own (account_id, machine_model_id,
 * code) unique index already uses for its nullable columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_document_imports', function (Blueprint $t) {
            $t->uuid('extraction_id')->nullable()->after('document_id');
            $t->timestampTz('processing_started_at')->nullable()->after('reviewed_at');
            $t->timestampTz('processing_completed_at')->nullable()->after('processing_started_at');
            $t->unsignedInteger('pages_processed')->default(0)->after('processing_completed_at');
            $t->unsignedInteger('candidate_count')->default(0)->after('pages_processed');
            // Bumped only if the detector's own logic changes in a way that should be
            // distinguishable from a prior run's candidates in reporting/debugging -
            // not tied to the extraction (a new extraction already gets its own import
            // row) and not a per-entry field (every entry in one processing run shares
            // the same detector version).
            $t->unsignedInteger('processing_version')->default(1)->after('candidate_count');

            $t->foreign('extraction_id')->references('id')->on('maintenance_document_extractions')->restrictOnDelete();
        });

        Schema::table('maintenance_knowledge_entries', function (Blueprint $t) {
            $t->uuid('extraction_id')->nullable()->after('import_id');
            $t->string('normalized_code', 64)->nullable()->after('code');
            $t->unsignedInteger('source_page_start')->nullable()->after('page_reference');
            $t->unsignedInteger('source_page_end')->nullable()->after('source_page_start');
            // Deterministic, explainable classification - never a fabricated
            // AI-style percentage (see PdfKnowledgeCandidateDetector). HIGH/MEDIUM/LOW.
            $t->string('evidence', 20)->nullable()->after('source_page_end');
            // Snapshot at detection time of whether normalized_code already exists in
            // machine_error_codes for this scope: NEW / EXISTING / POTENTIAL_UPDATE.
            // Null for manual entries (knowledge_type-driven, not code-collision-driven).
            $t->string('collision_status', 20)->nullable()->after('evidence');

            $t->foreign('extraction_id')->references('id')->on('maintenance_document_extractions')->restrictOnDelete();
            $t->unique(['import_id', 'normalized_code', 'source_page_start', 'source_page_end'], 'maintenance_knowledge_entry_identity_uq');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_knowledge_entries', function (Blueprint $t) {
            $t->dropUnique('maintenance_knowledge_entry_identity_uq');
            $t->dropForeign(['extraction_id']);
            $t->dropColumn(['extraction_id', 'normalized_code', 'source_page_start', 'source_page_end', 'evidence', 'collision_status']);
        });

        Schema::table('maintenance_document_imports', function (Blueprint $t) {
            $t->dropForeign(['extraction_id']);
            $t->dropColumn(['extraction_id', 'processing_started_at', 'processing_completed_at', 'pages_processed', 'candidate_count', 'processing_version']);
        });
    }
};
