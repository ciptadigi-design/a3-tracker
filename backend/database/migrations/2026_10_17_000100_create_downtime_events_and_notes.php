<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('downtime_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->uuid('branch_id');
            $t->uuid('machine_id');
            $t->string('cause', 60);
            $t->string('error_code_text', 16)->nullable();
            $t->text('description')->nullable();
            // actual_start_at/actual_end_at are the authoritative real-world event
            // timestamps (may be backdated by an authorized actor); created_at (below,
            // via timestamps()) is the immutable record-creation timestamp and is never
            // overwritten - see MaintenanceTicket's own opened_at/resolved_at precedent
            // for why these two notions of time are kept distinct.
            $t->timestampTz('actual_start_at');
            $t->timestampTz('actual_end_at')->nullable();
            $t->text('resolution_note')->nullable();
            $t->uuid('created_by');
            $t->uuid('closed_by')->nullable();
            $t->boolean('is_backdated')->default(false);
            // Null while active/closed; 'active' exactly while actual_end_at IS NULL.
            // Paired with the unique index below, this is the same idiom
            // component_lifecycles uses for "only one active X per Y" - MySQL unique
            // indexes treat NULL as distinct, so any number of closed (active_key=null)
            // events coexist, but only one 'active' row can exist per machine_id.
            $t->string('active_key', 16)->nullable();
            $t->uuid('client_request_id')->nullable();
            $t->timestamps();
            $t->unique(['id', 'account_id']);
            $t->unique(['account_id', 'client_request_id']);
            $t->unique(['machine_id', 'active_key'], 'downtime_event_active_uq');
            $t->index(['account_id', 'branch_id', 'actual_start_at']);
            $t->index(['machine_id', 'actual_start_at']);
            $t->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $t->foreign(['branch_id', 'account_id'])->references(['id', 'account_id'])->on('branches')->restrictOnDelete();
            $t->foreign(['machine_id', 'account_id'])->references(['id', 'account_id'])->on('machines')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('closed_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('downtime_notes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->uuid('downtime_event_id');
            $t->uuid('performed_by')->nullable();
            $t->text('note');
            $t->timestampTz('occurred_at')->useCurrent();
            $t->uuid('machine_component_id')->nullable();
            $t->uuid('component_replacement_id')->nullable();
            $t->uuid('client_request_id')->nullable();
            $t->timestamps();
            $t->unique(['account_id', 'client_request_id']);
            $t->index(['downtime_event_id', 'occurred_at']);
            $t->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $t->foreign(['downtime_event_id', 'account_id'])->references(['id', 'account_id'])->on('downtime_events')->restrictOnDelete();
            $t->foreign('machine_component_id')->references('id')->on('machine_components')->restrictOnDelete();
            $t->foreign('component_replacement_id')->references('id')->on('component_replacements')->restrictOnDelete();
            $t->foreign('performed_by')->references('id')->on('users')->nullOnDelete();
        });

        // Operator downtime management is policy-toggleable per account, matching
        // every other operator_can_* action in account_operational_permissions.
        Schema::table('account_operational_permissions', function (Blueprint $t) {
            $t->boolean('operator_can_manage_downtime')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('account_operational_permissions', function (Blueprint $t) {
            $t->dropColumn('operator_can_manage_downtime');
        });
        Schema::dropIfExists('downtime_notes');
        Schema::dropIfExists('downtime_events');
    }
};
