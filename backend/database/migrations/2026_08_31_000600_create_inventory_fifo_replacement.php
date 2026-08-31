<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->uuid('component_id')->nullable();
            $t->string('sku', 80);
            $t->string('name', 160);
            $t->string('category', 80)->nullable();
            $t->string('unit', 20)->default('pcs');
            $t->decimal('minimum_stock', 20, 4)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->unique(['id', 'account_id']);
            $t->unique(['account_id', 'sku'], 'inv_item_account_sku_uq');
            $t->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $t->foreign('component_id')->references('id')->on('component_catalogs')->restrictOnDelete();
            $t->index(['account_id', 'is_active', 'component_id'], 'inv_item_scope_idx');
        });
        Schema::create('inventory_locations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->uuid('branch_id')->nullable();
            $t->string('code', 64);
            $t->string('name', 160);
            $t->boolean('is_active')->default(true);
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->unique(['id', 'account_id']);
            $t->unique(['account_id', 'code'], 'inv_location_account_code_uq');
            $t->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $t->foreign(['branch_id', 'account_id'])->references(['id', 'account_id'])->on('branches')->restrictOnDelete();
            $t->index(['account_id', 'branch_id', 'is_active'], 'inv_location_scope_idx');
        });
        Schema::create('purchases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->string('purchase_number', 80);
            $t->date('purchase_date');
            $t->string('currency_code', 3)->default('IDR');
            $t->string('status', 24)->default('draft');
            $t->text('notes')->nullable();
            $t->uuid('client_request_id');
            $t->timestamps();
            $t->unique(['id', 'account_id']);
            $t->unique(['account_id', 'client_request_id'], 'purchase_request_uq');
            $t->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $t->index(['account_id', 'status', 'purchase_date'], 'purchase_scope_idx');
        });
        Schema::create('purchase_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->uuid('purchase_id');
            $t->uuid('inventory_item_id');
            $t->decimal('ordered_quantity', 20, 4);
            $t->decimal('unit_cost', 20, 2)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['purchase_id', 'inventory_item_id'], 'purchase_item_uq');
            $t->foreign(['purchase_id', 'account_id'])->references(['id', 'account_id'])->on('purchases')->restrictOnDelete();
            $t->foreign(['inventory_item_id', 'account_id'])->references(['id', 'account_id'])->on('inventory_items')->restrictOnDelete();
        });
        Schema::create('receipts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->uuid('purchase_id')->nullable();
            $t->uuid('location_id');
            $t->timestamp('received_at');
            $t->string('reference', 120)->nullable();
            $t->uuid('client_request_id');
            $t->timestamps();
            $t->unique(['id', 'account_id']);
            $t->unique(['account_id', 'client_request_id'], 'receipt_request_uq');
            $t->foreign(['purchase_id', 'account_id'])->references(['id', 'account_id'])->on('purchases')->restrictOnDelete();
            $t->foreign(['location_id', 'account_id'])->references(['id', 'account_id'])->on('inventory_locations')->restrictOnDelete();
        });
        Schema::create('receipt_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->uuid('receipt_id');
            $t->uuid('purchase_line_id')->nullable();
            $t->uuid('inventory_item_id');
            $t->decimal('quantity', 20, 4);
            $t->decimal('unit_cost', 20, 2)->nullable();
            $t->timestamps();
            $t->foreign(['receipt_id', 'account_id'])->references(['id', 'account_id'])->on('receipts')->restrictOnDelete();
            $t->foreign('purchase_line_id')->references('id')->on('purchase_lines')->restrictOnDelete();
            $t->foreign(['inventory_item_id', 'account_id'])->references(['id', 'account_id'])->on('inventory_items')->restrictOnDelete();
        });
        Schema::create('inventory_movements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->uuid('inventory_item_id');
            $t->uuid('location_id');
            $t->string('movement_type', 32);
            $t->decimal('quantity', 20, 4);
            $t->timestamp('occurred_at');
            $t->string('reference_type', 40);
            $t->uuid('reference_id')->nullable();
            $t->text('reason')->nullable();
            $t->uuid('client_request_id');
            $t->uuid('transfer_id')->nullable();
            $t->timestamps();
            $t->unique(['id', 'account_id']);
            $t->unique(['account_id', 'client_request_id', 'movement_type', 'location_id'], 'movement_request_leg_uq');
            $t->foreign(['inventory_item_id', 'account_id'])->references(['id', 'account_id'])->on('inventory_items')->restrictOnDelete();
            $t->foreign(['location_id', 'account_id'])->references(['id', 'account_id'])->on('inventory_locations')->restrictOnDelete();
            $t->index(['account_id', 'inventory_item_id', 'location_id', 'occurred_at'], 'movement_balance_idx');
        });
        Schema::create('fifo_layers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->uuid('inventory_item_id');
            $t->uuid('location_id');
            $t->uuid('inbound_movement_id');
            $t->string('source_type', 32);
            $t->unsignedBigInteger('fifo_sequence');
            $t->decimal('original_quantity', 20, 4);
            $t->decimal('remaining_quantity', 20, 4);
            $t->decimal('unit_cost', 20, 2)->nullable();
            $t->timestamp('effective_at');
            $t->uuid('origin_layer_id')->nullable();
            $t->timestamps();
            $t->foreign('inbound_movement_id')->references('id')->on('inventory_movements')->restrictOnDelete();
            $t->foreign(['inventory_item_id', 'account_id'])->references(['id', 'account_id'])->on('inventory_items')->restrictOnDelete();
            $t->foreign(['location_id', 'account_id'])->references(['id', 'account_id'])->on('inventory_locations')->restrictOnDelete();
            $t->unique(['inventory_item_id', 'location_id', 'fifo_sequence'], 'fifo_layer_sequence_uq');
            $t->index(['inventory_item_id', 'location_id', 'fifo_sequence', 'remaining_quantity'], 'fifo_layer_order_idx');
        });
        Schema::create('fifo_allocations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->uuid('outbound_movement_id');
            $t->uuid('fifo_layer_id');
            $t->decimal('quantity', 20, 4);
            $t->decimal('unit_cost', 20, 2)->nullable();
            $t->decimal('allocated_cost', 30, 2)->nullable();
            $t->unsignedInteger('allocation_order');
            $t->timestamps();
            $t->unique(['outbound_movement_id', 'fifo_layer_id'], 'fifo_alloc_movement_layer_uq');
            $t->foreign('outbound_movement_id')->references('id')->on('inventory_movements')->restrictOnDelete();
            $t->foreign('fifo_layer_id')->references('id')->on('fifo_layers')->restrictOnDelete();
        });
        Schema::create('component_replacements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('account_id');
            $t->uuid('machine_component_id');
            $t->uuid('inventory_item_id')->nullable();
            $t->uuid('inventory_location_id')->nullable();
            $t->uuid('inventory_movement_id')->nullable();
            $t->uuid('previous_lifecycle_id')->nullable();
            $t->uuid('new_lifecycle_id');
            $t->string('inventory_source', 32);
            $t->decimal('quantity', 20, 4)->nullable();
            $t->decimal('consumed_cost', 30, 2)->nullable();
            $t->timestamp('replaced_at');
            $t->text('external_reason')->nullable();
            $t->text('notes')->nullable();
            $t->uuid('client_request_id');
            $t->timestamps();
            $t->unique(['account_id', 'client_request_id'], 'replacement_request_uq');
            $t->foreign('machine_component_id')->references('id')->on('machine_components')->restrictOnDelete();
            $t->foreign('inventory_item_id')->references('id')->on('inventory_items')->restrictOnDelete();
            $t->foreign('inventory_location_id')->references('id')->on('inventory_locations')->restrictOnDelete();
            $t->foreign('inventory_movement_id')->references('id')->on('inventory_movements')->restrictOnDelete();
            $t->foreign('previous_lifecycle_id')->references('id')->on('component_lifecycles')->restrictOnDelete();
            $t->foreign('new_lifecycle_id')->references('id')->on('component_lifecycles')->restrictOnDelete();
            $t->index(['machine_component_id', 'replaced_at'], 'replacement_history_idx');
        });
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE fifo_layers ADD CONSTRAINT fifo_remaining_nonnegative_ck CHECK (remaining_quantity >= 0 AND remaining_quantity <= original_quantity)');
            DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movement_nonzero_ck CHECK (quantity <> 0)');
            $variables = DB::selectOne('SELECT @@GLOBAL.log_bin AS log_bin, @@GLOBAL.log_bin_trust_function_creators AS trust_creators');
            $trustFunctionCreators = (string) ($variables->trust_creators ?? 'OFF') === '1';
            $binaryLogging = (string) ($variables->log_bin ?? 'OFF') === '1';
            if (! $binaryLogging || $trustFunctionCreators) {
                DB::unprepared("CREATE TRIGGER inventory_movements_immutable_update BEFORE UPDATE ON inventory_movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inventory movements are immutable'");
                DB::unprepared("CREATE TRIGGER inventory_movements_immutable_delete BEFORE DELETE ON inventory_movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inventory movements are immutable'");
            }
        } elseif (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER inventory_movements_immutable_update BEFORE UPDATE ON inventory_movements BEGIN SELECT RAISE(ABORT, 'inventory movements are immutable'); END");
            DB::unprepared("CREATE TRIGGER inventory_movements_immutable_delete BEFORE DELETE ON inventory_movements BEGIN SELECT RAISE(ABORT, 'inventory movements are immutable'); END");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql' || Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS inventory_movements_immutable_update');
            DB::unprepared('DROP TRIGGER IF EXISTS inventory_movements_immutable_delete');
        } foreach (['component_replacements', 'fifo_allocations', 'fifo_layers', 'inventory_movements', 'receipt_lines', 'receipts', 'purchase_lines', 'purchases', 'inventory_locations', 'inventory_items'] as $x) {
            Schema::dropIfExists($x);
        }
    }
};
