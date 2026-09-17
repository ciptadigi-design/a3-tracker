<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_document_references', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('document_id');
            $t->uuid('machine_error_code_id')->nullable();
            $t->string('reference_type', 40)->default('error_code');
            $t->unsignedInteger('page_number')->nullable();
            $t->string('section_title', 200)->nullable();
            $t->text('notes')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestamps();
            // No account_id here - scope is inherited entirely from the parent document
            // (and, when set, the referenced error code), the same child-has-no-scope-
            // of-its-own pattern maintenance_error_solutions already uses.
            $t->index(['document_id']);
            $t->index(['machine_error_code_id']);
            $t->foreign('document_id')->references('id')->on('maintenance_documents')->restrictOnDelete();
            $t->foreign('machine_error_code_id')->references('id')->on('machine_error_codes')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_document_references');
    }
};
