<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('accounts', function (Blueprint $t) { $t->uuid('id')->primary(); $t->string('code',64)->unique(); $t->string('name',120); $t->string('default_timezone',64)->default('Asia/Jakarta'); $t->string('status',24)->default('active')->index(); $t->timestampTz('archived_at')->nullable(); $t->timestampsTz(); $t->engine='InnoDB'; });
        Schema::create('account_memberships', function (Blueprint $t) { $t->uuid('id')->primary(); $t->foreignUuid('account_id')->constrained()->restrictOnDelete(); $t->foreignUuid('user_id')->constrained()->restrictOnDelete(); $t->string('role',24); $t->string('status',24)->default('active')->index(); $t->timestampsTz(); $t->unique(['account_id','user_id']); $t->engine='InnoDB'; });
        Schema::create('branches', function (Blueprint $t) { $t->uuid('id')->primary(); $t->foreignUuid('account_id')->constrained()->restrictOnDelete(); $t->string('code',32); $t->string('name',120); $t->string('timezone',64)->nullable(); $t->boolean('is_active')->default(true)->index(); $t->timestampTz('archived_at')->nullable(); $t->timestampsTz(); $t->unique(['account_id','code']); $t->engine='InnoDB'; });
        Schema::create('platform_user_privileges', function (Blueprint $t) { $t->foreignUuid('user_id')->primary()->constrained()->cascadeOnDelete(); $t->string('role',32)->default('superuser'); $t->boolean('is_active')->default(true)->index(); $t->timestampsTz(); $t->engine='InnoDB'; });
    }
    public function down(): void { Schema::dropIfExists('platform_user_privileges'); Schema::dropIfExists('branches'); Schema::dropIfExists('account_memberships'); Schema::dropIfExists('accounts'); }
};
