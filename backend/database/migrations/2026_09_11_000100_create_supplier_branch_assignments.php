<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_suppliers', function (Blueprint $table) {
            $table->unique(['id', 'account_id'], 'inventory_suppliers_id_account_unique');
        });

        // Supplier identity stays account-owned (inventory_suppliers). This table only
        // records which branches a supplier is currently operationally available in.
        // A supplier with zero rows here is available to every branch in its account -
        // that is the legacy/default state, so existing suppliers stay visible everywhere
        // without a backfill. Once an admin assigns specific branches, visibility narrows
        // to exactly those branches (see InventoryController::workspace()).
        Schema::create('supplier_branch_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->uuid('supplier_id');
            $table->uuid('branch_id');
            $table->timestamps();
            $table->unique(['supplier_id', 'branch_id'], 'supplier_branch_assignments_unique');
            $table->index(['account_id', 'branch_id'], 'supplier_branch_assignments_scope_idx');
            $table->foreign(['supplier_id', 'account_id'], 'supplier_branch_assignments_supplier_fk')->references(['id', 'account_id'])->on('inventory_suppliers')->cascadeOnDelete();
            $table->foreign(['branch_id', 'account_id'], 'supplier_branch_assignments_branch_fk')->references(['id', 'account_id'])->on('branches')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_branch_assignments');
        Schema::table('inventory_suppliers', function (Blueprint $table) {
            $table->dropUnique('inventory_suppliers_id_account_unique');
        });
    }
};
