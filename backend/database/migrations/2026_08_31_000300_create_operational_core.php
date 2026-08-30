<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id')->nullable();
            $t->string('code', 64);
            $t->string('name', 160);
            $t->text('notes')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->index(['account_id', 'is_active']);
            $t->unique(['account_id', 'code']);
            $t->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
        });
        Schema::create('machine_models', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id')->nullable();
            $t->uuid('manufacturer_id');
            $t->string('model_code', 64);
            $t->string('name', 160);
            $t->string('machine_category', 40)->default('digital_a3');
            $t->string('color_capability', 20)->default('color');
            $t->text('description')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->index(['manufacturer_id', 'is_active']);
            $t->unique(['account_id', 'manufacturer_id', 'model_code']);
            $t->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $t->foreign('manufacturer_id')->references('id')->on('manufacturers')->restrictOnDelete();
        });
        Schema::create('machines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->uuid('branch_id');
            $t->uuid('machine_model_id');
            $t->string('machine_code', 80);
            $t->string('display_name', 180);
            $t->string('serial_number', 120)->nullable();
            $t->date('installed_on')->nullable();
            $t->string('status', 20)->default('active');
            $t->string('timezone', 64)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['id', 'account_id']);
            $t->unique(['account_id', 'machine_code']);
            $t->index(['account_id', 'branch_id', 'status']);
            $t->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $t->foreign(['branch_id', 'account_id'])->references(['id', 'account_id'])->on('branches')->restrictOnDelete();
            $t->foreign('machine_model_id')->references('id')->on('machine_models')->restrictOnDelete();
        });
        Schema::create('counter_types', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 64)->unique();
            $t->string('name', 120);
            $t->string('unit', 30)->default('count');
            $t->unsignedTinyInteger('decimal_scale')->default(0);
            $t->boolean('is_monotonic')->default(true);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('operational_people', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->string('name', 160);
            $t->string('code', 64)->nullable();
            $t->uuid('linked_user_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->text('notes')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->unique(['id', 'account_id']);
            $t->unique(['account_id', 'name']);
            $t->index(['account_id', 'is_active']);
            $t->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $t->foreign('linked_user_id')->references('id')->on('users')->nullOnDelete();
        });
        Schema::create('operational_person_branches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->uuid('person_id');
            $t->uuid('branch_id');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['person_id', 'branch_id']);
            $t->index(['branch_id', 'is_active']);
            $t->foreign(['person_id', 'account_id'])->references(['id', 'account_id'])->on('operational_people')->cascadeOnDelete();
            $t->foreign(['branch_id', 'account_id'])->references(['id', 'account_id'])->on('branches')->cascadeOnDelete();
        });
        Schema::create('counter_readings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->uuid('machine_id');
            $t->uuid('counter_type_id');
            $t->decimal('reading_value', 20, 4);
            $t->timestampTz('observed_at');
            $t->string('shift_code', 2)->nullable();
            $t->uuid('operator_person_id')->nullable();
            $t->string('operator_name_snapshot', 160)->nullable();
            $t->uuid('entered_by')->nullable();
            $t->string('source', 40)->default('manual');
            $t->uuid('previous_reading_id')->nullable();
            $t->string('status', 20)->default('effective');
            $t->text('notes')->nullable();
            $t->uuid('client_request_id');
            $t->timestampTz('created_at')->nullable();
            $t->timestampTz('updated_at')->nullable();
            $t->unique(['account_id', 'client_request_id']);
            $t->index(['machine_id', 'observed_at']);
            $t->foreign(['machine_id', 'account_id'])->references(['id', 'account_id'])->on('machines')->restrictOnDelete();
            $t->foreign('counter_type_id')->references('id')->on('counter_types')->restrictOnDelete();
            $t->foreign(['operator_person_id', 'account_id'])->references(['id', 'account_id'])->on('operational_people')->restrictOnDelete();
            $t->foreign('entered_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('previous_reading_id')->references('id')->on('counter_readings')->nullOnDelete();
        });
        DB::table('counter_types')->insertOrIgnore(['id' => '00000000-0000-0000-0000-000000000001', 'code' => 'total_impressions', 'name' => 'Total Impressions', 'unit' => 'count', 'decimal_scale' => 0, 'is_monotonic' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('counter_readings');
        Schema::dropIfExists('operational_person_branches');
        Schema::dropIfExists('operational_people');
        Schema::dropIfExists('counter_types');
        Schema::dropIfExists('machines');
        Schema::dropIfExists('machine_models');
        Schema::dropIfExists('manufacturers');
    }
};
