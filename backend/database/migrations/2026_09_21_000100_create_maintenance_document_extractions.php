<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_document_extractions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('document_id');
            $t->string('status', 20)->default('PENDING');
            $t->unsignedInteger('total_pages')->nullable();
            $t->unsignedInteger('processed_pages')->default(0);
            $t->text('error_message')->nullable();
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestamps();
            // No account_id here - scope is inherited entirely from the parent document
            // (maintenance_documents.account_id), the same child-has-no-scope-of-its-own
            // pattern maintenance_error_solutions/maintenance_document_references/
            // maintenance_document_imports already use.
            //
            // cascadeOnDelete (unlike document_references/document_imports, which
            // restrictOnDelete): an extraction record is derived, disposable process
            // state tied to one document's PDF, not a durable cross-reference another
            // resource points at - if the document goes, its extraction history should
            // go with it rather than blocking deletion.
            $t->index(['document_id', 'status']);
            $t->foreign('document_id')->references('id')->on('maintenance_documents')->cascadeOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_document_extractions');
    }
};
