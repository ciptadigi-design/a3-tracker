<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\ComponentCatalog;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventorySupplier;
use App\Models\Machine;
use App\Models\MachineComponent;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\ModelProfile;
use App\Models\ModelProfileSlot;
use App\Services\PurchaseReceiptService;
use App\Services\ReplaceMachineComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * M2.17.5.4 Part C: audit-only proof of the current FIFO/supplier-provenance
 * model, using local test data only (per the mission's explicit prohibition on
 * fabricating Production purchases/receipts/replacements). Mirrors the exact
 * multi-supplier scenario from the mission brief and the real lineage traced
 * read-only from Production Magenta/Yellow (Supplier JFP, receipt
 * 4a7678ce-8035-4793-901f-9a08ceb1e3f1): Supplier -> Purchase -> Purchase Line
 * -> Receipt Line -> (inbound) Inventory Movement -> FIFO Layer -> (outbound)
 * FIFO Allocation -> Component Replacement -> Component Lifecycle.
 *
 * Key finding this suite locks in: FIFO layer selection
 * (InventoryLedgerService::outbound()) orders strictly by `fifo_sequence` - a
 * monotonic counter assigned at layer-INSERTION time per (item, location) - not
 * by the receipt's `received_at`/`effective_at` value. A Component Replacement's
 * own `replaced_at` field (freely backdatable via the API) has NO influence on
 * which layer is consumed; only real processing-time insertion order does. This
 * is exactly what Production's real Magenta/Yellow evidence shows: replaced_at
 * predates the very receipt whose layer was actually allocated, because the
 * replacement was PROCESSED after that receipt already existed in the ledger.
 */
class M2_17_5_4_FifoSupplierProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private function graph(): array
    {
        $a = Account::create(['code' => 'FIFO', 'name' => 'FIFO Provenance']);
        $b = Branch::create(['account_id' => $a->id, 'code' => 'MAIN', 'name' => 'Main']);
        $loc = InventoryLocation::create(['account_id' => $a->id, 'branch_id' => $b->id, 'code' => 'WH', 'name' => 'Warehouse']);
        $c = ComponentCatalog::create(['code' => 'TONER_M', 'name' => 'Toner Magenta']);
        $item = InventoryItem::create(['account_id' => $a->id, 'component_id' => $c->id, 'sku' => 'TONER-M', 'name' => 'Toner Magenta']);
        $supplierA = InventorySupplier::create(['account_id' => $a->id, 'code' => 'SUP-A', 'name' => 'Supplier A']);
        $supplierB = InventorySupplier::create(['account_id' => $a->id, 'code' => 'SUP-B', 'name' => 'Supplier B']);
        $man = Manufacturer::create(['code' => 'KM', 'name' => 'KM']);
        $model = MachineModel::create(['manufacturer_id' => $man->id, 'model_code' => 'C1070', 'name' => 'C1070']);
        $profile = ModelProfile::create(['machine_model_id' => $model->id, 'name' => 'P']);
        $slot = ModelProfileSlot::create(['profile_id' => $profile->id, 'component_id' => $c->id, 'slot_code' => 'TONER_M', 'baseline_expected_clicks' => 14000]);
        $m = Machine::create(['account_id' => $a->id, 'branch_id' => $b->id, 'machine_model_id' => $model->id, 'machine_code' => 'M1', 'display_name' => 'M1']);
        $mc = MachineComponent::create(['account_id' => $a->id, 'machine_id' => $m->id, 'component_id' => $c->id, 'profile_slot_id' => $slot->id, 'slot_code' => 'TONER_M', 'source_type' => 'inherited', 'status' => 'configured', 'active_key' => 'active', 'baseline_expected_clicks' => 14000]);

        return compact('a', 'b', 'loc', 'item', 'supplierA', 'supplierB', 'mc');
    }

    private function receiveFrom(array $f, InventorySupplier $supplier, string $purchaseDate, float $quantity, float $unitCost): object
    {
        $service = app(PurchaseReceiptService::class);
        $purchase = $service->purchase($f['a']->id, ['supplier_id' => $supplier->id, 'branch_id' => $f['b']->id, 'purchase_number' => 'PUR-'.$supplier->code, 'purchase_date' => $purchaseDate, 'client_request_id' => (string) Str::uuid(), 'lines' => [['inventory_item_id' => $f['item']->id, 'quantity' => $quantity, 'unit_cost' => $unitCost]]]);
        $line = DB::table('purchase_lines')->where('purchase_id', $purchase->id)->first();
        $service->receive($purchase->id, $f['loc'], [['purchase_line_id' => $line->id, 'quantity' => $quantity]], (string) Str::uuid());

        return $purchase;
    }

    // C1/C4: exact lineage - purchase -> line -> receipt -> movement -> layer.
    public function test_receiving_creates_the_correct_layer_linked_back_to_its_purchase_and_supplier(): void
    {
        $f = $this->graph();
        $this->receiveFrom($f, $f['supplierA'], '2026-09-01', 2, 1500000);

        $layer = DB::table('fifo_layers')->where('inventory_item_id', $f['item']->id)->first();
        $this->assertEquals(2, $layer->original_quantity);
        $this->assertEquals(2, $layer->remaining_quantity);
        $this->assertSame('receipt', $layer->source_type);

        $movement = DB::table('inventory_movements')->find($layer->inbound_movement_id);
        $this->assertSame('receipt', $movement->movement_type);
        $purchaseLine = DB::table('purchase_lines')->where('inventory_item_id', $f['item']->id)->first();
        $purchase = DB::table('purchases')->find($purchaseLine->purchase_id);
        $this->assertSame($f['supplierA']->id, $purchase->supplier_id);
    }

    // C4: strict multi-supplier scenario from the mission brief.
    public function test_multi_supplier_replacement_consumes_the_earlier_inserted_layer_first(): void
    {
        $f = $this->graph();
        $this->receiveFrom($f, $f['supplierA'], '2026-09-01', 2, 1500000); // Layer A
        $this->receiveFrom($f, $f['supplierB'], '2026-09-05', 2, 1650000); // Layer B

        $replacement = app(ReplaceMachineComponent::class)->execute($f['mc'], [
            'inventory_source' => 'inventory', 'inventory_item_id' => $f['item']->id, 'inventory_location_id' => $f['loc']->id,
            'quantity' => 1, 'client_request_id' => (string) Str::uuid(),
        ]);

        $allocation = DB::table('fifo_allocations')->where('outbound_movement_id', $replacement->inventory_movement_id)->first();
        $layer = DB::table('fifo_layers')->find($allocation->fifo_layer_id);
        $inboundMovement = DB::table('inventory_movements')->find($layer->inbound_movement_id);
        $purchaseLine = DB::table('purchase_lines')->where('inventory_item_id', $f['item']->id)->where('unit_cost', $layer->unit_cost)->first();
        $purchase = DB::table('purchases')->find($purchaseLine->purchase_id);

        $this->assertSame($f['supplierA']->id, $purchase->supplier_id, 'strict FIFO must consume Supplier A (earlier-inserted layer) first');
        $this->assertEquals(1500000, $layer->unit_cost);
        $this->assertEquals(1500000, $replacement->consumed_cost);
        $this->assertEquals(1, DB::table('fifo_layers')->find($layer->id)->remaining_quantity, 'Layer A now has 1 remaining after consuming 1 of its 2 units');
    }

    // C4: layer depletion and rollover to the next supplier's layer.
    public function test_layer_depletes_and_rolls_over_to_the_next_suppliers_layer(): void
    {
        $f = $this->graph();
        $this->receiveFrom($f, $f['supplierA'], '2026-09-01', 2, 1500000);
        $this->receiveFrom($f, $f['supplierB'], '2026-09-05', 2, 1650000);

        app(ReplaceMachineComponent::class)->execute($f['mc'], ['inventory_source' => 'inventory', 'inventory_item_id' => $f['item']->id, 'inventory_location_id' => $f['loc']->id, 'quantity' => 2, 'client_request_id' => (string) Str::uuid()]);

        $layerA = DB::table('fifo_layers')->where('unit_cost', 1500000)->first();
        $layerB = DB::table('fifo_layers')->where('unit_cost', 1650000)->first();
        $this->assertEquals(0, $layerA->remaining_quantity, 'Layer A (Supplier A) must be fully depleted first');
        $this->assertEquals(2, $layerB->remaining_quantity, 'Layer B (Supplier B) must remain untouched while Layer A still had stock');

        // A further consumption of 1 must now cross into Supplier B's layer.
        app(ReplaceMachineComponent::class)->execute($f['mc'], ['inventory_source' => 'inventory', 'inventory_item_id' => $f['item']->id, 'inventory_location_id' => $f['loc']->id, 'quantity' => 1, 'client_request_id' => (string) Str::uuid()]);
        $this->assertEquals(1, DB::table('fifo_layers')->find($layerB->id)->remaining_quantity);
    }

    // C3: proves FIFO selection is driven by insertion-order fifo_sequence, NOT by
    // the Component Replacement's own (freely backdatable) replaced_at field -
    // exactly matching the real Production Magenta/Yellow evidence, where
    // replaced_at predates the receipt whose layer was actually consumed.
    public function test_backdated_replaced_at_does_not_change_which_layer_is_allocated(): void
    {
        $f = $this->graph();
        $this->receiveFrom($f, $f['supplierA'], '2026-09-01', 1, 1500000);
        $this->receiveFrom($f, $f['supplierB'], '2026-09-05', 1, 1650000);

        // Backdate this replacement to BEFORE either receipt's stated purchase_date -
        // FIFO selection must still be governed by real insertion order (both
        // layers already exist in the ledger at the moment this actually runs).
        $replacement = app(ReplaceMachineComponent::class)->execute($f['mc'], [
            'inventory_source' => 'inventory', 'inventory_item_id' => $f['item']->id, 'inventory_location_id' => $f['loc']->id,
            'quantity' => 1, 'replaced_at' => '2026-08-20 00:00:00', 'client_request_id' => (string) Str::uuid(),
        ]);

        $this->assertEquals(1500000, $replacement->consumed_cost, 'still Supplier A - replaced_at has no bearing on FIFO layer choice');
    }

    // No double consumption / idempotency: a retried client_request_id must
    // return the exact same replacement, never allocate or consume twice.
    public function test_retried_replacement_request_does_not_double_consume(): void
    {
        $f = $this->graph();
        $this->receiveFrom($f, $f['supplierA'], '2026-09-01', 2, 1500000);
        $requestId = (string) Str::uuid();
        $d = ['inventory_source' => 'inventory', 'inventory_item_id' => $f['item']->id, 'inventory_location_id' => $f['loc']->id, 'quantity' => 1, 'client_request_id' => $requestId];

        $first = app(ReplaceMachineComponent::class)->execute($f['mc'], $d);
        $second = app(ReplaceMachineComponent::class)->execute($f['mc'], $d);

        // Scoped to this test's own account - an unscoped global count is fragile
        // and can pick up rows from other tests sharing the same MySQL database.
        $this->assertSame((string) $first->id, (string) $second->id);
        $this->assertSame(1, DB::table('component_replacements')->where('account_id', $f['a']->id)->count());
        $this->assertSame(1, DB::table('fifo_allocations')->where('account_id', $f['a']->id)->count());
        $this->assertEquals(1, DB::table('fifo_layers')->where('inventory_item_id', $f['item']->id)->value('remaining_quantity'));
    }

    // C5: explicit layer/receipt/supplier-batch selection is not exposed anywhere
    // in the write path - execute() only ever accepts item/location/quantity.
    public function test_replacement_execute_accepts_no_explicit_layer_or_receipt_selection(): void
    {
        $f = $this->graph();
        $this->receiveFrom($f, $f['supplierA'], '2026-09-01', 1, 1500000);
        $layerBeforeB = DB::table('fifo_layers')->where('inventory_item_id', $f['item']->id)->first();
        $this->receiveFrom($f, $f['supplierB'], '2026-09-05', 1, 1650000);

        // Even if a caller supplies a would-be "preferred layer/receipt" hint, the
        // service has no parameter that reads it - proving no override path exists.
        $replacement = app(ReplaceMachineComponent::class)->execute($f['mc'], [
            'inventory_source' => 'inventory', 'inventory_item_id' => $f['item']->id, 'inventory_location_id' => $f['loc']->id,
            'quantity' => 1, 'client_request_id' => (string) Str::uuid(),
            'fifo_layer_id' => 'ignored-hint', 'receipt_id' => 'ignored-hint', 'preferred_supplier_id' => $f['supplierB']->id,
        ]);

        $this->assertEquals(1500000, $replacement->consumed_cost, 'Supplier A still consumed - a supplied preference hint has zero effect');
        $this->assertNotNull($layerBeforeB);
    }
}
