<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_incidents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->uuid('branch_id');
            $t->uuid('machine_id')->nullable();
            $t->timestampTz('occurred_at');
            $t->string('invoice_number', 120)->nullable();
            $t->string('customer_name_snapshot', 180)->nullable();
            $t->string('product_name_snapshot', 180)->nullable();
            $t->string('category', 40);
            $t->string('incident_type', 40);
            $t->unsignedInteger('qty_affected')->nullable();
            $t->uuid('operator_person_id')->nullable();
            $t->string('operator_name_snapshot', 160)->nullable();
            $t->uuid('responsible_person_id')->nullable();
            $t->string('responsible_name_snapshot', 160)->nullable();
            $t->decimal('material_loss', 20, 2)->default(0);
            $t->decimal('service_loss', 20, 2)->default(0);
            $t->decimal('penalty_multiplier', 8, 4)->default(1);
            $t->decimal('assessed_loss', 20, 2)->nullable();
            $t->text('description');
            $t->text('cause')->nullable();
            $t->text('prevention')->nullable();
            $t->text('customer_resolution')->nullable();
            $t->string('status', 20)->default('open');
            $t->uuid('client_request_id');
            $t->uuid('created_by')->nullable();
            $t->uuid('updated_by')->nullable();
            $t->timestampTz('resolved_at')->nullable();
            $t->uuid('resolved_by')->nullable();
            $t->text('resolution_note')->nullable();
            $t->timestampTz('voided_at')->nullable();
            $t->uuid('voided_by')->nullable();
            $t->text('void_reason')->nullable();
            $t->timestampsTz();
            $t->unique(['account_id', 'client_request_id'], 'incident_request_uq');
            $t->index(['account_id', 'branch_id', 'occurred_at'], 'incident_branch_period_idx');
            $t->index(['account_id', 'machine_id', 'occurred_at'], 'incident_machine_period_idx');
            $t->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $t->foreign(['branch_id', 'account_id'])->references(['id', 'account_id'])->on('branches')->restrictOnDelete();
            $t->foreign(['machine_id', 'account_id'])->references(['id', 'account_id'])->on('machines')->restrictOnDelete();
            // The composite account key cannot be SET NULL because account_id is
            // immutable evidence. Operational people are archived, not deleted.
            $t->foreign(['operator_person_id', 'account_id'])->references(['id', 'account_id'])->on('operational_people')->restrictOnDelete();
            $t->foreign(['responsible_person_id', 'account_id'])->references(['id', 'account_id'])->on('operational_people')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_incidents');
    }
};
