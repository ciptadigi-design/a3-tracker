<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\ComponentCatalog;
use App\Models\ComponentLifecycle;
use App\Models\ComponentReplacement;
use App\Models\FifoAllocation;
use App\Models\FifoLayer;
use App\Models\InventoryComponentCompatibility;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Machine;
use App\Models\MachineComponent;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\ModelProfile;
use App\Models\ModelProfileSlot;
use App\Services\InventoryLedgerService;
use App\Services\ReplaceMachineComponent;
use App\Services\ReplacementCostReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * Controlled administrative reconciliation of a historical component
 * replacement whose cost was originally recorded as unknown (the exact
 * real Production shape: an adjustment_in/opening_balance posted with
 * unit_cost=NULL, later consumed by a replacement, leaving
 * fifo_layers.unit_cost / fifo_allocations.allocated_cost /
 * component_replacements.consumed_cost all NULL with no purchase/receipt
 * trail to derive from).
 */
class ReplacementCostReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $account = Account::create(['code' => 'CG', 'name' => 'Cipta Grafika']);
        $branch = Branch::create(['account_id' => $account->id, 'code' => 'MAIN', 'name' => 'Main']);
        $loc = InventoryLocation::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'code' => 'WH', 'name' => 'Warehouse']);
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'KM']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'C', 'name' => 'C']);
        $profile = ModelProfile::create(['machine_model_id' => $model->id, 'name' => 'P']);
        $component = ComponentCatalog::create(['account_id' => $account->id, 'code' => 'corona_k', 'name' => 'Charging Corona K', 'is_active' => true]);
        $slot = ModelProfileSlot::create(['profile_id' => $profile->id, 'component_id' => $component->id, 'slot_code' => 'K']);
        $machine = Machine::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'M1', 'display_name' => 'M1', 'status' => 'active']);
        $mc = MachineComponent::create(['account_id' => $account->id, 'machine_id' => $machine->id, 'component_id' => $component->id, 'profile_slot_id' => $slot->id, 'slot_code' => 'K', 'source_type' => 'inherited', 'status' => 'configured', 'active_key' => 'active']);
        ComponentLifecycle::create(['machine_component_id' => $mc->id, 'started_at' => now()->subDays(10), 'status' => 'active', 'active_key' => 'active', 'source' => 'manual']);
        $item = InventoryItem::create(['account_id' => $account->id, 'component_id' => $component->id, 'sku' => 'CORONA-K', 'name' => 'Charging Corona Black', 'is_active' => true]);
        InventoryComponentCompatibility::create(['account_id' => $account->id, 'inventory_item_id' => $item->id, 'component_id' => $component->id, 'is_active' => true]);

        return compact('account', 'branch', 'loc', 'item', 'mc');
    }

    /** Builds the exact real-world unknown-cost chain: unknown-cost inbound, then a replacement consuming it. */
    private function unknownCostReplacement(array $f, float $quantity = 1): ComponentReplacement
    {
        app(InventoryLedgerService::class)->inbound($f['item'], $f['loc'], $quantity, null, 'adjustment_in', (string) Str::uuid(), 'Stock Correction');

        return app(ReplaceMachineComponent::class)->execute($f['mc'], [
            'inventory_source' => 'inventory', 'inventory_item_id' => $f['item']->id, 'inventory_location_id' => $f['loc']->id,
            'quantity' => $quantity, 'client_request_id' => (string) Str::uuid(),
        ]);
    }

    // 1. Dry-run makes zero mutations.
    public function test_dry_run_makes_zero_mutations(): void
    {
        $f = $this->fixture();
        $replacement = $this->unknownCostReplacement($f);
        $layer = FifoLayer::first();
        $allocation = FifoAllocation::first();

        $result = app(ReplacementCostReconciliationService::class)->reconcile($replacement->id, 1100000, false);

        $this->assertSame('DRY_RUN', $result['status']);
        $this->assertNull($layer->fresh()->unit_cost);
        $this->assertNull($allocation->fresh()->allocated_cost);
        $this->assertNull($replacement->fresh()->consumed_cost);
        $this->assertSame(1100000.0, $result['after']['fifo_layer_unit_cost']);
        $this->assertSame(1100000.0, $result['after']['allocation_allocated_cost']);
        $this->assertSame(1100000.0, $result['after']['replacement_consumed_cost']);
    }

    // 2-5. --apply reconciles a valid single-allocation unknown-cost chain:
    // layer unit_cost, allocation allocated_cost (quantity x unit cost), and
    // replacement consumed_cost are all updated correctly.
    public function test_apply_reconciles_the_full_chain_correctly(): void
    {
        $f = $this->fixture();
        $replacement = $this->unknownCostReplacement($f);
        $layer = FifoLayer::first();
        $allocation = FifoAllocation::first();

        $result = app(ReplacementCostReconciliationService::class)->reconcile($replacement->id, 1100000, true);

        $this->assertSame('APPLIED', $result['status']);
        $this->assertSame(1100000.0, (float) $layer->fresh()->unit_cost);
        $this->assertSame(1100000.0, (float) $allocation->fresh()->allocated_cost);
        $this->assertSame(1100000.0, (float) $replacement->fresh()->consumed_cost);
    }

    // 6. inventory_movements unchanged (immutable, never touched).
    public function test_inventory_movements_are_never_touched(): void
    {
        $f = $this->fixture();
        $replacement = $this->unknownCostReplacement($f);
        $movement = InventoryMovement::find($replacement->inventory_movement_id);
        $before = $movement->toArray();

        app(ReplacementCostReconciliationService::class)->reconcile($replacement->id, 1100000, true);

        $this->assertSame($before, $movement->fresh()->toArray());
    }

    // 7. Inventory balance unchanged.
    public function test_inventory_balance_unchanged(): void
    {
        $f = $this->fixture();
        $replacement = $this->unknownCostReplacement($f);
        $balanceBefore = app(InventoryLedgerService::class)->balance($f['item']->id, $f['loc']->id);

        app(ReplacementCostReconciliationService::class)->reconcile($replacement->id, 1100000, true);

        $this->assertSame($balanceBefore, app(InventoryLedgerService::class)->balance($f['item']->id, $f['loc']->id));
    }

    // 8. FIFO remaining quantity unchanged.
    public function test_fifo_remaining_quantity_unchanged(): void
    {
        $f = $this->fixture();
        $replacement = $this->unknownCostReplacement($f);
        $layer = FifoLayer::first();
        $remainingBefore = (float) $layer->remaining_quantity;

        app(ReplacementCostReconciliationService::class)->reconcile($replacement->id, 1100000, true);

        $this->assertSame($remainingBefore, (float) $layer->fresh()->remaining_quantity);
    }

    // 9. Lifecycle unchanged.
    public function test_lifecycle_unchanged(): void
    {
        $f = $this->fixture();
        $replacement = $this->unknownCostReplacement($f);
        $lifecycleBefore = ComponentLifecycle::find($replacement->new_lifecycle_id)->toArray();

        app(ReplacementCostReconciliationService::class)->reconcile($replacement->id, 1100000, true);

        $this->assertSame($lifecycleBefore, ComponentLifecycle::find($replacement->new_lifecycle_id)->toArray());
    }

    // 10. Missing replacement fails.
    public function test_missing_replacement_fails(): void
    {
        $this->expectException(ConflictHttpException::class);
        app(ReplacementCostReconciliationService::class)->reconcile((string) Str::uuid(), 1100000, false);
    }

    // 11. A replacement whose movement is not replacement_consumption fails.
    public function test_non_replacement_movement_fails(): void
    {
        $f = $this->fixture();
        $movement = app(InventoryLedgerService::class)->inbound($f['item'], $f['loc'], 5, null, 'adjustment_in', (string) Str::uuid(), 'Stock Correction');
        $fakeReplacement = ComponentReplacement::create([
            'account_id' => $f['account']->id, 'machine_component_id' => $f['mc']->id, 'inventory_item_id' => $f['item']->id,
            'inventory_location_id' => $f['loc']->id, 'inventory_movement_id' => $movement->id, 'new_lifecycle_id' => ComponentLifecycle::first()->id,
            'inventory_source' => 'inventory', 'quantity' => 5, 'replaced_at' => now(), 'client_request_id' => (string) Str::uuid(),
        ]);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage("not 'replacement_consumption'");
        app(ReplacementCostReconciliationService::class)->reconcile($fakeReplacement->id, 1100000, false);
    }

    // 12. Multiple FIFO allocations fail closed (not yet supported).
    public function test_multiple_allocations_fail_closed(): void
    {
        $f = $this->fixture();
        app(InventoryLedgerService::class)->inbound($f['item'], $f['loc'], 1, null, 'adjustment_in', (string) Str::uuid(), 'Stock Correction 1');
        app(InventoryLedgerService::class)->inbound($f['item'], $f['loc'], 1, null, 'adjustment_in', (string) Str::uuid(), 'Stock Correction 2');
        $replacement = app(ReplaceMachineComponent::class)->execute($f['mc'], [
            'inventory_source' => 'inventory', 'inventory_item_id' => $f['item']->id, 'inventory_location_id' => $f['loc']->id,
            'quantity' => 2, 'client_request_id' => (string) Str::uuid(),
        ]);
        $this->assertSame(2, FifoAllocation::count(), 'sanity: two layers of 1 unit each must require two allocations for a 2-unit consumption');

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('multi-allocation reconciliation is not supported');
        app(ReplacementCostReconciliationService::class)->reconcile($replacement->id, 1100000, false);
    }

    // 13. An existing conflicting known cost fails closed - never overwrite a known cost with a different value.
    public function test_existing_conflicting_known_cost_fails_closed(): void
    {
        $f = $this->fixture();
        $replacement = $this->unknownCostReplacement($f);
        app(ReplacementCostReconciliationService::class)->reconcile($replacement->id, 1100000, true);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('refusing to overwrite a known historical cost');
        app(ReplacementCostReconciliationService::class)->reconcile($replacement->id, 1200000, true);
    }

    // 14. A partially-known/inconsistent chain fails closed.
    public function test_partially_known_chain_fails_closed(): void
    {
        $f = $this->fixture();
        $replacement = $this->unknownCostReplacement($f);
        // Simulate a partially-reconciled chain: only the layer got fixed somehow.
        FifoLayer::first()->update(['unit_cost' => 1100000]);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('only partially known');
        app(ReplacementCostReconciliationService::class)->reconcile($replacement->id, 1100000, true);
    }

    // 15. Rerunning with the SAME cost after a successful reconciliation is a safe no-op.
    public function test_rerun_with_same_cost_is_idempotent_no_op(): void
    {
        $f = $this->fixture();
        $replacement = $this->unknownCostReplacement($f);
        app(ReplacementCostReconciliationService::class)->reconcile($replacement->id, 1100000, true);

        $result = app(ReplacementCostReconciliationService::class)->reconcile($replacement->id, 1100000, true);

        $this->assertSame('ALREADY_RECONCILED', $result['status']);
        $this->assertSame(1100000.0, (float) FifoLayer::first()->unit_cost);
        $this->assertSame(1, FifoAllocation::count());
    }

    // 16. A late failure inside the reconciliation transaction rolls back everything already applied.
    public function test_late_failure_rolls_back_the_whole_transaction(): void
    {
        $f = $this->fixture();
        $replacement = $this->unknownCostReplacement($f);
        $layer = FifoLayer::first();
        $allocation = FifoAllocation::first();

        DB::listen(function ($query) {
            if (stripos($query->sql, 'update') !== false && stripos($query->sql, 'component_replacements') !== false) {
                throw new \RuntimeException('SIMULATED_LATE_FAILURE');
            }
        });

        try {
            app(ReplacementCostReconciliationService::class)->reconcile($replacement->id, 1100000, true);
            $this->fail('expected the simulated failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('SIMULATED_LATE_FAILURE', $e->getMessage());
        } finally {
            DB::getEventDispatcher()->forget('Illuminate\Database\Events\QueryExecuted');
        }

        $this->assertNull($layer->fresh()->unit_cost, 'the layer update must roll back too, not just the replacement update');
        $this->assertNull($allocation->fresh()->allocated_cost);
        $this->assertNull($replacement->fresh()->consumed_cost);
    }

    // 17. MachineCostService correctly flips this replacement from unknown to known.
    public function test_machine_cost_service_reflects_unknown_to_known(): void
    {
        $f = $this->fixture();
        $replacement = $this->unknownCostReplacement($f);
        $machine = Machine::find($f['mc']->machine_id);
        $from = now()->subDay()->toDateString();
        $to = now()->addDay()->toDateString();

        $before = app(\App\Services\MachineCostService::class)->period($machine, $from, $to, false);
        $this->assertSame(1, $before['unknown_consumption_events']);
        $this->assertSame('0.00', $before['component_consumption_cost']);

        app(ReplacementCostReconciliationService::class)->reconcile($replacement->id, 1100000, true);

        $after = app(\App\Services\MachineCostService::class)->period($machine, $from, $to, false);
        $this->assertSame(0, $after['unknown_consumption_events']);
        $this->assertSame('1100000.00', $after['component_consumption_cost']);
    }
}
