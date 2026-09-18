<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V1.5.3 - additive, nullable machine-readable failure classification
 * (PdfExtractionErrorCode) alongside the existing free-text error_message. Nullable
 * and additive only - existing extraction rows are never modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_document_extractions', function (Blueprint $t) {
            $t->string('error_code', 40)->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_document_extractions', function (Blueprint $t) {
            $t->dropColumn('error_code');
        });
    }
};
