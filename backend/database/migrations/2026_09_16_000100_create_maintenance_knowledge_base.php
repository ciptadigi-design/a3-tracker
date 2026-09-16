<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id')->nullable();
            $t->uuid('manufacturer_id')->nullable();
            $t->uuid('machine_model_id')->nullable();
            $t->string('title', 200);
            $t->string('document_type', 40)->default('service_manual');
            $t->string('file_reference', 500);
            $t->string('version', 40)->nullable();
            $t->uuid('uploaded_by')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->index(['account_id', 'is_active']);
            $t->index(['machine_model_id']);
            $t->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $t->foreign('manufacturer_id')->references('id')->on('manufacturers')->restrictOnDelete();
            $t->foreign('machine_model_id')->references('id')->on('machine_models')->restrictOnDelete();
            $t->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('machine_error_codes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id')->nullable();
            $t->uuid('machine_model_id')->nullable();
            $t->string('code', 64);
            $t->string('title', 200);
            $t->string('category', 80)->nullable();
            $t->string('severity', 20)->default('warning');
            $t->text('manufacturer_description')->nullable();
            $t->text('operator_description')->nullable();
            $t->text('official_solution')->nullable();
            $t->uuid('source_document_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            // account_id and machine_model_id are both nullable (global manufacturer codes vs.
            // tenant-added custom codes) - matching manufacturers/machine_models above, MySQL/SQLite
            // treat NULL as distinct in a unique index, the same accepted trade-off those tables use.
            $t->unique(['account_id', 'machine_model_id', 'code']);
            $t->index(['account_id', 'is_active']);
            $t->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $t->foreign('machine_model_id')->references('id')->on('machine_models')->restrictOnDelete();
            $t->foreign('source_document_id')->references('id')->on('maintenance_documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_error_codes');
        Schema::dropIfExists('maintenance_documents');
    }
};
