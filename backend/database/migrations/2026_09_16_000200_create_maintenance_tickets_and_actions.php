<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_tickets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->uuid('branch_id');
            $t->uuid('machine_id');
            $t->uuid('machine_component_id')->nullable();
            $t->uuid('error_code_id')->nullable();
            $t->string('type', 24)->default('breakdown');
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->string('priority', 20)->default('normal');
            $t->string('status', 20)->default('OPEN');
            $t->uuid('reported_by')->nullable();
            $t->uuid('assigned_to')->nullable();
            // Downtime is opened_at -> resolved_at, always. No separate manually-settable
            // duration/instant field exists - that would let a client fabricate downtime
            // independent of the ticket's own real, server-set lifecycle timestamps.
            $t->timestampTz('opened_at')->useCurrent();
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('resolved_at')->nullable();
            $t->uuid('client_request_id')->nullable();
            $t->timestamps();
            $t->unique(['id', 'account_id']);
            $t->unique(['account_id', 'client_request_id']);
            $t->index(['account_id', 'status']);
            $t->index(['machine_id', 'status']);
            $t->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $t->foreign(['branch_id', 'account_id'])->references(['id', 'account_id'])->on('branches')->restrictOnDelete();
            $t->foreign(['machine_id', 'account_id'])->references(['id', 'account_id'])->on('machines')->restrictOnDelete();
            $t->foreign('machine_component_id')->references('id')->on('machine_components')->restrictOnDelete();
            $t->foreign('error_code_id')->references('id')->on('machine_error_codes')->restrictOnDelete();
            $t->foreign('reported_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('maintenance_actions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->uuid('ticket_id');
            $t->uuid('component_replacement_id')->nullable();
            $t->text('action_description');
            $t->string('result', 40)->nullable();
            $t->uuid('performed_by')->nullable();
            $t->timestampTz('performed_at')->useCurrent();
            $t->timestamps();
            $t->index(['ticket_id', 'performed_at']);
            $t->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $t->foreign(['ticket_id', 'account_id'])->references(['id', 'account_id'])->on('maintenance_tickets')->restrictOnDelete();
            $t->foreign('component_replacement_id')->references('id')->on('component_replacements')->restrictOnDelete();
            $t->foreign('performed_by')->references('id')->on('users')->nullOnDelete();
        });

        // Operator ticket creation is policy-toggleable per account, matching every other
        // operator_can_* action in account_operational_permissions / EffectiveCapabilityResolver.
        Schema::table('account_operational_permissions', function (Blueprint $t) {
            $t->boolean('operator_can_create_maintenance_ticket')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('account_operational_permissions', function (Blueprint $t) {
            $t->dropColumn('operator_can_create_maintenance_ticket');
        });
        Schema::dropIfExists('maintenance_actions');
        Schema::dropIfExists('maintenance_tickets');
    }
};
