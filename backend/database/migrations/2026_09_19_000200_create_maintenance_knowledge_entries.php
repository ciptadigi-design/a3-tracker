<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_knowledge_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('import_id');
            $t->string('knowledge_type', 20);
            $t->string('code', 64)->nullable();
            $t->string('title', 200);
            $t->string('category', 80)->nullable();
            $t->string('severity', 20)->nullable();
            $t->text('description')->nullable();
            $t->text('operator_solution')->nullable();
            $t->text('technician_solution')->nullable();
            $t->string('page_reference', 40)->nullable();
            $t->string('status', 20)->default('DRAFT');
            $t->uuid('created_by')->nullable();
            $t->uuid('approved_by')->nullable();
            // Not in the original field list, but required to make publish() idempotent -
            // this is a new table (nothing existing depends on its shape yet), so recording
            // exactly when an approved entry was turned into machine_error_codes/
            // maintenance_error_solutions/maintenance_document_references is an
            // implementation necessity, not a scope change.
            $t->timestampTz('published_at')->nullable();
            $t->timestamps();
            // No account_id here either - inherits scope via import_id -> maintenance_document_imports
            // -> document_id -> maintenance_documents.account_id.
            $t->index(['import_id', 'status']);
            $t->foreign('import_id')->references('id')->on('maintenance_document_imports')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_knowledge_entries');
    }
};
