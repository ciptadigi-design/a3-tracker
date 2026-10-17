<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Branch-level default weekly schedule. A branch is "configured" iff it has
        // all 7 day_of_week rows (0=Sunday..6=Saturday, matching Carbon::dayOfWeek) -
        // written together by one replace-all request, never partially - so "not
        // configured" (zero rows) is never ambiguous with "configured but closed
        // that day" (is_operating=false).
        Schema::create('branch_production_schedules', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->uuid('branch_id');
            $t->unsignedTinyInteger('day_of_week');
            $t->boolean('is_operating')->default(false);
            $t->time('start_time')->nullable();
            $t->time('end_time')->nullable();
            // When true, end_time is on the following calendar day (overnight shift).
            $t->boolean('crosses_midnight')->default(false);
            $t->timestamps();
            $t->unique(['branch_id', 'day_of_week']);
            $t->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $t->foreign(['branch_id', 'account_id'])->references(['id', 'account_id'])->on('branches')->restrictOnDelete();
        });

        // Machine-specific override of the branch default, same shape, same
        // all-7-days-at-once replace semantics. Resolution order: machine override
        // row for that day_of_week, else the branch default row, else "not
        // configured" (see ProductionScheduleResolver).
        Schema::create('machine_production_schedule_overrides', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->uuid('branch_id');
            $t->uuid('machine_id');
            $t->unsignedTinyInteger('day_of_week');
            $t->boolean('is_operating')->default(false);
            $t->time('start_time')->nullable();
            $t->time('end_time')->nullable();
            $t->boolean('crosses_midnight')->default(false);
            $t->timestamps();
            $t->unique(['machine_id', 'day_of_week']);
            $t->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $t->foreign(['branch_id', 'account_id'])->references(['id', 'account_id'])->on('branches')->restrictOnDelete();
            $t->foreign(['machine_id', 'account_id'])->references(['id', 'account_id'])->on('machines')->restrictOnDelete();
        });

        // One-off date overrides (holiday, overtime, extra shift, closed). machine_id
        // null = branch-wide; a machine-specific row overrides a branch-wide row for
        // the same date. Uniqueness (one row per branch/machine/date) is enforced at
        // the application layer, not a DB constraint - MySQL unique indexes treat
        // NULL as always-distinct, so a branch-wide (machine_id IS NULL) uniqueness
        // rule cannot be expressed as a plain composite unique index without a
        // sentinel value hack. This is low-frequency, admin-entered data, so a
        // service-layer check-then-insert inside a transaction is an accepted
        // trade-off (documented in the M2 Downtime & Maintenance V1 plan).
        Schema::create('production_schedule_exceptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->uuid('branch_id');
            $t->uuid('machine_id')->nullable();
            $t->date('exception_date');
            $t->string('type', 24);
            $t->boolean('is_operating')->default(false);
            $t->time('override_start_time')->nullable();
            $t->time('override_end_time')->nullable();
            $t->boolean('crosses_midnight')->default(false);
            $t->text('note')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestamps();
            $t->index(['branch_id', 'exception_date']);
            $t->index(['machine_id', 'exception_date']);
            $t->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $t->foreign(['branch_id', 'account_id'])->references(['id', 'account_id'])->on('branches')->restrictOnDelete();
            $t->foreign(['machine_id', 'account_id'])->references(['id', 'account_id'])->on('machines')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_schedule_exceptions');
        Schema::dropIfExists('machine_production_schedule_overrides');
        Schema::dropIfExists('branch_production_schedules');
    }
};
