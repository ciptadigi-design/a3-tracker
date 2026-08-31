<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('component_catalogs', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('account_id')->nullable(); $t->string('code',64); $t->string('name',160); $t->text('description')->nullable(); $t->string('category',80)->nullable(); $t->string('tracking_method',32)->default('counter_based'); $t->boolean('is_active')->default(true); $t->timestamp('archived_at')->nullable(); $t->timestamps();
            $t->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete(); $t->index(['account_id','is_active']); $t->unique(['account_id','code']);
        });
        Schema::create('model_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('account_id')->nullable(); $t->uuid('machine_model_id'); $t->string('name',160); $t->boolean('is_active')->default(true); $t->timestamp('archived_at')->nullable(); $t->timestamps();
            $t->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete(); $t->foreign('machine_model_id')->references('id')->on('machine_models')->restrictOnDelete(); $t->index(['machine_model_id','account_id','is_active']);
        });
        Schema::create('model_profile_slots', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('profile_id'); $t->uuid('component_id'); $t->string('slot_code',80); $t->string('slot_name',160)->nullable(); $t->unsignedInteger('display_order')->default(0); $t->string('tracking_method',32)->default('counter_based'); $t->unsignedBigInteger('baseline_expected_clicks')->nullable(); $t->boolean('is_active')->default(true); $t->timestamp('archived_at')->nullable(); $t->timestamps();
            $t->foreign('profile_id')->references('id')->on('model_profiles')->restrictOnDelete(); $t->foreign('component_id')->references('id')->on('component_catalogs')->restrictOnDelete(); $t->unique(['profile_id','slot_code']); $t->index(['profile_id','is_active','display_order']);
        });
        Schema::create('machine_components', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('account_id'); $t->uuid('machine_id'); $t->uuid('component_id'); $t->uuid('profile_slot_id')->nullable(); $t->string('slot_code',80); $t->string('source_type',24); $t->string('status',24)->default('configured'); $t->string('active_key',24)->nullable(); $t->unsignedInteger('display_order')->default(0); $t->timestamps();
            $t->foreign(['machine_id','account_id'])->references(['id','account_id'])->on('machines')->restrictOnDelete(); $t->foreign('component_id')->references('id')->on('component_catalogs')->restrictOnDelete(); $t->foreign('profile_slot_id')->references('id')->on('model_profile_slots')->restrictOnDelete(); $t->unique(['machine_id','slot_code','active_key']); $t->index(['machine_id','status']);
        });
        Schema::create('machine_component_exclusions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('account_id'); $t->uuid('machine_id'); $t->uuid('profile_slot_id'); $t->string('slot_code',80); $t->text('reason'); $t->uuid('cleared_by')->nullable(); $t->timestamp('cleared_at')->nullable(); $t->uuid('client_request_id')->nullable(); $t->timestamps();
            $t->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete(); $t->foreign('machine_id')->references('id')->on('machines')->restrictOnDelete(); $t->foreign('profile_slot_id')->references('id')->on('model_profile_slots')->restrictOnDelete(); $t->unique(['machine_id','profile_slot_id','cleared_at']); $t->index(['machine_id','profile_slot_id','cleared_at']);
        });
        Schema::create('component_lifecycles', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('machine_component_id'); $t->timestamp('started_at')->nullable(); $t->timestamp('ended_at')->nullable(); $t->string('status',24)->default('active'); $t->string('evidence_level',1)->nullable(); $t->string('source',40)->default('manual'); $t->text('notes')->nullable(); $t->uuid('client_request_id')->nullable(); $t->string('active_key',24)->nullable(); $t->timestamps();
            $t->foreign('machine_component_id')->references('id')->on('machine_components')->restrictOnDelete(); $t->unique(['machine_component_id','active_key']); $t->unique(['client_request_id']); $t->index(['machine_component_id','started_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('component_lifecycles'); Schema::dropIfExists('machine_component_exclusions'); Schema::dropIfExists('machine_components'); Schema::dropIfExists('model_profile_slots'); Schema::dropIfExists('model_profiles'); Schema::dropIfExists('component_catalogs'); }
};
