<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_operational_permissions', function (Blueprint $table) {
            $table->uuid('account_id')->primary();
            foreach (['operator_can_initialize_component', 'operator_can_replace_component', 'operator_can_create_purchase', 'operator_can_receive_goods', 'operator_can_adjust_inventory', 'operator_can_transfer_inventory', 'operator_can_log_errors'] as $column) {
                $table->boolean($column)->default(false);
            }
            $table->timestamps();
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
        });
        Schema::create('inventory_suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->string('code', 80);
            $table->string('name', 160);
            $table->string('contact_name', 160)->nullable();
            $table->string('phone', 80)->nullable();
            $table->string('email', 254)->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['account_id', 'code']);
            $table->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
        });
        Schema::table('purchases', function (Blueprint $table) {
            $table->uuid('branch_id')->nullable()->after('account_id');
            $table->uuid('supplier_id')->nullable()->after('branch_id');
            $table->string('external_reference', 160)->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreign(['branch_id', 'account_id'])->references(['id', 'account_id'])->on('branches')->restrictOnDelete();
            $table->foreign('supplier_id')->references('id')->on('inventory_suppliers')->restrictOnDelete();
        });
        Schema::create('operational_incident_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('incident_id');
            $table->uuid('changed_by')->nullable();
            $table->timestamp('changed_at');
            $table->text('change_reason')->nullable();
            $table->json('old_values');
            $table->json('new_values');
            $table->json('changed_fields');
            $table->timestamps();
            $table->foreign('incident_id')->references('id')->on('operational_incidents')->restrictOnDelete();
            $table->foreign('changed_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['account_id', 'incident_id', 'changed_at'], 'incident_revision_scope_idx');
        });
        Schema::create('machine_selling_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('machine_id');
            $table->decimal('price_per_click', 20, 4);
            $table->timestamp('effective_from');
            $table->string('status', 20)->default('posted');
            $table->text('notes')->nullable();
            $table->uuid('client_request_id');
            $table->uuid('created_by')->nullable();
            $table->string('created_by_name_snapshot', 160)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->uuid('voided_by')->nullable();
            $table->string('voided_by_name_snapshot', 160)->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'client_request_id']);
            $table->foreign(['machine_id', 'account_id'])->references(['id', 'account_id'])->on('machines')->restrictOnDelete();
        });
        Schema::create('machine_operating_costs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('machine_id');
            $table->string('category', 60);
            $table->decimal('amount', 20, 2);
            $table->string('allocation_method', 40);
            $table->text('description');
            $table->timestamp('effective_at')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->uuid('operational_person_id')->nullable();
            $table->string('external_reference', 160)->nullable();
            $table->text('notes')->nullable();
            $table->string('source_type', 32)->default('manual');
            $table->string('status', 20)->default('posted');
            $table->uuid('client_request_id');
            $table->timestamp('voided_at')->nullable();
            $table->uuid('voided_by')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'client_request_id']);
            $table->foreign(['machine_id', 'account_id'])->references(['id', 'account_id'])->on('machines')->restrictOnDelete();
            $table->foreign('operational_person_id')->references('id')->on('operational_people')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_operating_costs');
        Schema::dropIfExists('machine_selling_prices');
        Schema::dropIfExists('operational_incident_revisions');
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->dropForeign(['branch_id', 'account_id']);
            $table->dropColumn(['branch_id', 'supplier_id', 'external_reference', 'cancel_reason']);
        });
        Schema::dropIfExists('inventory_suppliers');
        Schema::dropIfExists('account_operational_permissions');
    }
};
