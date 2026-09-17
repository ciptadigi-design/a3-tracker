<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Additive only - maintenance_documents already shipped in V1 (file_reference,
        // uploaded_by, is_active/archived_at) and is already referenced by
        // machine_error_codes.source_document_id in production. Nothing existing is
        // renamed or dropped; `status` becomes the new authoritative lifecycle field
        // going forward, kept in sync with is_active/archived_at by the controller so
        // the existing global-or-owned catalog scope check (ScopedReference::
        // activeGlobalOrOwned(), which reads is_active) keeps working unmodified.
        // file_reference (V1's original required generic reference string) is superseded
        // by the structured file_path/file_name/file_size/mime_type fields below, but
        // is left NOT NULL as-is - relaxing it needs doctrine/dbal (not installed in
        // this project) for a portable ->change(), and a raw driver-specific ALTER is
        // unnecessary risk for a column nothing new reads. MaintenanceDocumentController
        // satisfies the existing constraint by defaulting it from file_path when absent.
        Schema::table('maintenance_documents', function (Blueprint $t) {
            $t->text('description')->nullable()->after('title');
            $t->string('file_path', 500)->nullable()->after('file_reference');
            $t->string('file_name', 255)->nullable()->after('file_path');
            $t->unsignedBigInteger('file_size')->nullable()->after('file_name');
            $t->string('mime_type', 100)->nullable()->after('file_size');
            $t->string('status', 20)->default('DRAFT')->after('is_active');
            $t->index(['account_id', 'status'], 'maintenance_documents_status_idx');
        });

        DB::table('maintenance_documents')->where('is_active', true)->update(['status' => 'PUBLISHED']);
        DB::table('maintenance_documents')->where('is_active', false)->update(['status' => 'ARCHIVED']);
    }

    public function down(): void
    {
        Schema::table('maintenance_documents', function (Blueprint $t) {
            $t->dropIndex('maintenance_documents_status_idx');
            $t->dropColumn(['description', 'file_path', 'file_name', 'file_size', 'mime_type', 'status']);
        });
    }
};
