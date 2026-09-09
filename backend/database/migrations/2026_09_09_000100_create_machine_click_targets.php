<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_click_targets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->uuid('branch_id');
            $t->uuid('machine_id');
            $t->unsignedSmallInteger('target_year');
            $t->unsignedTinyInteger('target_month');
            $t->unsignedInteger('monthly_click_target');
            $t->uuid('created_by')->nullable();
            $t->uuid('updated_by')->nullable();
            $t->timestampsTz();
            $t->unique(['machine_id', 'target_year', 'target_month'], 'click_target_machine_month_uq');
            $t->index(['account_id', 'branch_id', 'target_year', 'target_month'], 'click_target_branch_period_idx');
            $t->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $t->foreign(['branch_id', 'account_id'], 'click_target_branch_fk')->references(['id', 'account_id'])->on('branches')->restrictOnDelete();
            $t->foreign(['machine_id', 'account_id'], 'click_target_machine_fk')->references(['id', 'account_id'])->on('machines')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        // Insert-only audit trail. The currently effective target lives in
        // machine_click_targets; every create/revise writes one row here so
        // "previous target / new target / changed at / changed by / reason"
        // is always answerable without reconstructing historical daily plans.
        Schema::create('machine_click_target_revisions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->uuid('machine_id');
            $t->unsignedSmallInteger('target_year');
            $t->unsignedTinyInteger('target_month');
            $t->unsignedInteger('previous_target')->nullable();
            $t->unsignedInteger('new_target');
            $t->text('reason')->nullable();
            $t->uuid('changed_by')->nullable();
            $t->uuid('client_request_id')->nullable();
            // Monotonic per-machine ordering key. created_at alone is not a safe
            // sort key: MySQL/SQLite timestamp columns commonly resolve only to
            // whole seconds, so two revisions submitted in the same transaction
            // (or the same second) would tie. sequence is assigned inside the
            // same row-locked transaction as the target upsert, so it is always
            // strictly increasing per machine regardless of clock resolution.
            $t->unsignedBigInteger('sequence')->default(0);
            $t->timestampTz('created_at', 6);
            $t->unique(['account_id', 'client_request_id'], 'click_target_revision_request_uq');
            $t->index(['account_id', 'machine_id', 'target_year', 'target_month'], 'click_target_revision_period_idx');
            $t->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $t->foreign(['machine_id', 'account_id'], 'click_target_revision_machine_fk')->references(['id', 'account_id'])->on('machines')->restrictOnDelete();
            $t->foreign('changed_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('machine_operational_calendar_exceptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->uuid('branch_id');
            $t->uuid('machine_id');
            $t->date('calendar_date');
            $t->string('exception_type', 40);
            $t->text('notes')->nullable();
            $t->boolean('excluded_from_target')->default(true);
            $t->uuid('client_request_id')->nullable();
            $t->uuid('created_by')->nullable();
            $t->uuid('updated_by')->nullable();
            $t->timestampsTz();
            $t->unique(['machine_id', 'calendar_date'], 'calendar_exception_machine_date_uq');
            $t->unique(['account_id', 'client_request_id'], 'calendar_exception_request_uq');
            $t->index(['account_id', 'branch_id', 'calendar_date'], 'calendar_exception_branch_date_idx');
            $t->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $t->foreign(['branch_id', 'account_id'], 'calendar_exception_branch_fk')->references(['id', 'account_id'])->on('branches')->restrictOnDelete();
            $t->foreign(['machine_id', 'account_id'], 'calendar_exception_machine_fk')->references(['id', 'account_id'])->on('machines')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_operational_calendar_exceptions');
        Schema::dropIfExists('machine_click_target_revisions');
        Schema::dropIfExists('machine_click_targets');
    }
};
