<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_error_solutions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('machine_error_code_id');
            $t->unsignedInteger('step_number');
            $t->text('instruction');
            $t->boolean('requires_technician')->default(false);
            $t->uuid('created_by')->nullable();
            $t->timestamps();
            // No account_id here - tenant/global scope is inherited entirely from the
            // parent machine_error_codes row (the same "child has no scope column of its
            // own" pattern model_profile_slots uses relative to model_profiles).
            $t->unique(['machine_error_code_id', 'step_number'], 'maintenance_error_solution_step_uq');
            $t->foreign('machine_error_code_id')->references('id')->on('machine_error_codes')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_error_solutions');
    }
};
