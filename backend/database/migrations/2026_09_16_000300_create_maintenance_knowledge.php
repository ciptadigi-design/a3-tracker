<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_knowledge', function (Blueprint $t) {
            $t->uuid('id')->primary();
            // Internal knowledge is always tenant-owned - unlike documents/error codes
            // (manufacturer-issued catalog data, legitimately global), an approved
            // troubleshooting write-up is specific to one account's equipment and
            // history and must never be promotable to a shared/global record.
            $t->uuid('account_id');
            $t->uuid('machine_model_id')->nullable();
            $t->uuid('error_code_id')->nullable();
            $t->uuid('source_ticket_id')->nullable();
            $t->text('problem');
            $t->text('symptoms')->nullable();
            $t->text('solution');
            $t->text('success_notes')->nullable();
            // Review workflow: DRAFT -> REVIEW -> PUBLISHED (REVIEW can also send back to DRAFT).
            $t->string('approval_status', 20)->default('DRAFT');
            $t->uuid('submitted_by')->nullable();
            $t->timestampTz('submitted_at')->nullable();
            $t->uuid('reviewed_by')->nullable();
            $t->timestampTz('reviewed_at')->nullable();
            $t->timestampTz('published_at')->nullable();
            $t->timestamps();
            $t->index(['account_id', 'approval_status']);
            $t->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $t->foreign('machine_model_id')->references('id')->on('machine_models')->restrictOnDelete();
            $t->foreign('error_code_id')->references('id')->on('machine_error_codes')->restrictOnDelete();
            $t->foreign('source_ticket_id')->references('id')->on('maintenance_tickets')->restrictOnDelete();
            $t->foreign('submitted_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_knowledge');
    }
};
