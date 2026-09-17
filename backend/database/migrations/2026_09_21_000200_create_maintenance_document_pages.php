<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_document_pages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('document_id');
            $t->unsignedInteger('page_number');
            $t->longText('raw_text')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            // No account_id - inherited from the parent document, same pattern as
            // every other maintenance child table. Keyed by document_id (not
            // extraction_id): a re-run extraction upserts the same page rows rather
            // than accumulating a new full page set per attempt - the document's
            // extracted content is a single current snapshot, and
            // maintenance_document_extractions already carries per-attempt history.
            $t->unique(['document_id', 'page_number']);
            $t->foreign('document_id')->references('id')->on('maintenance_documents')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_document_pages');
    }
};
