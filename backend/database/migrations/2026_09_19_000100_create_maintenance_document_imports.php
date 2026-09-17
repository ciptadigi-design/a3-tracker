<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_document_imports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('document_id');
            $t->uuid('machine_model_id')->nullable();
            $t->string('status', 20)->default('DRAFT');
            $t->string('import_type', 20)->default('MANUAL_ENTRY');
            $t->uuid('created_by')->nullable();
            $t->uuid('reviewed_by')->nullable();
            $t->timestampTz('reviewed_at')->nullable();
            $t->timestamps();
            // No account_id here - scope is inherited entirely from the parent document
            // (maintenance_documents.account_id), the same child-has-no-scope-of-its-own
            // pattern maintenance_error_solutions/maintenance_document_references already use.
            $t->index(['document_id', 'status']);
            $t->foreign('document_id')->references('id')->on('maintenance_documents')->restrictOnDelete();
            $t->foreign('machine_model_id')->references('id')->on('machine_models')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_document_imports');
    }
};
