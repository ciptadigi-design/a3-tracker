<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\ComponentCatalog;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventorySupplier;
use App\Services\InventoryLedgerService;
use App\Services\PurchaseReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * M2.19 (closing the M2.18 audit's FIFO-receipt-provenance finding): before
 * this milestone, InventoryLedgerService::inbound() never populated
 * inventory_movements.reference_id for a receipt - the column existed (it's
 * the same polymorphic reference outbound() already exposes for e.g.
 * component_replacement) but nothing wrote it, so a remaining FIFO layer
 * could only be traced back to "a" purchase line matching the item (and, as
 * M2_17_5_4_FifoSupplierProvenanceTest's own second test shows, sometimes
 * only by matching unit_cost as a workaround), not the exact receipt line it
 * came from. Two suppliers charging the identical unit cost makes the old
 * unit_cost-matching approach genuinely ambiguous - this suite exists
 * specifically to prove the new reference_id chain is not.
 *
 * No schema change: fifo_layers.inbound_movement_id, inventory_movements.
 * reference_id/reference_type, receipt_lines.purchase_line_id,
 * purchase_lines.purchase_id, and purchases.supplier_id all already existed.
 */
class M2_19_2_FifoExactReceiptProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $a = Account::create(['code' => 'PROV', 'name' => 'Provenance']);
        $b = Branch::create(['account_id' => $a->id, 'code' => 'MAIN', 'name' => 'Main']);
        $loc = InventoryLocation::create(['account_id' => $a->id, 'branch_id' => $b->id, 'code' => 'WH', 'name' => 'Warehouse']);
        $c = ComponentCatalog::create(['code' => 'TONER_X', 'name' => 'Toner X']);
        $item = InventoryItem::create(['account_id' => $a->id, 'component_id' => $c->id, 'sku' => 'TONER-X', 'name' => 'Toner X']);
        $supplierA = InventorySupplier::create(['account_id' => $a->id, 'code' => 'SUP-A', 'name' => 'Supplier A']);
        $supplierB = InventorySupplier::create(['account_id' => $a->id, 'code' => 'SUP-B', 'name' => 'Supplier B']);

        return compact('a', 'b', 'loc', 'item', 'supplierA', 'supplierB');
    }

    private function receiveFrom(array $f, InventorySupplier $supplier, string $purchaseDate, float $quantity, float $unitCost): object
    {
        $service = app(PurchaseReceiptService::class);
        $purchase = $service->purchase($f['a']->id, ['supplier_id' => $supplier->id, 'branch_id' => $f['b']->id, 'purchase_number' => 'PUR-'.$supplier->code.'-'.Str::random(6), 'purchase_date' => $purchaseDate, 'client_request_id' => (string) Str::uuid(), 'lines' => [['inventory_item_id' => $f['item']->id, 'quantity' => $quantity, 'unit_cost' => $unitCost]]]);
        $line = DB::table('purchase_lines')->where('purchase_id', $purchase->id)->first();
        $service->receive($purchase->id, $f['loc'], [['purchase_line_id' => $line->id, 'quantity' => $quantity]], (string) Str::uuid());

        return $purchase;
    }

    /**
     * Deterministically resolves a FIFO layer back to its supplier via the
     * exact reference chain, mirroring exactly what a real support/audit
     * query would run - no unit_cost or timestamp correlation involved.
     */
    private function exactSupplierFor(object $layer): ?object
    {
        $movement = DB::table('inventory_movements')->find($layer->inbound_movement_id);
        if ($movement->reference_type !== 'receipt_line' || ! $movement->reference_id) {
            return null;
        }
        $receiptLine = DB::table('receipt_lines')->find($movement->reference_id);
        $purchaseLine = DB::table('purchase_lines')->find($receiptLine->purchase_line_id);
        $purchase = DB::table('purchases')->find($purchaseLine->purchase_id);

        return DB::table('inventory_suppliers')->find($purchase->supplier_id);
    }

    public function test_a_receipt_movement_records_exact_receipt_line_provenance(): void
    {
        $f = $this->fixture();
        $this->receiveFrom($f, $f['supplierA'], '2026-09-01', 2, 1500000);

        $layer = DB::table('fifo_layers')->where('inventory_item_id', $f['item']->id)->first();
        $movement = DB::table('inventory_movements')->find($layer->inbound_movement_id);

        $this->assertSame('receipt_line', $movement->reference_type);
        $this->assertNotNull($movement->reference_id);
        $receiptLine = DB::table('receipt_lines')->find($movement->reference_id);
        $this->assertNotNull($receiptLine, 'reference_id must resolve to a real receipt_line row');
        $this->assertSame((string) $f['item']->id, (string) $receiptLine->inventory_item_id);
    }

    public function test_exact_provenance_distinguishes_two_suppliers_at_the_identical_unit_cost(): void
    {
        // Both suppliers charge the SAME unit cost on purpose - the old
        // unit_cost-matching workaround (M2_17_5_4_FifoSupplierProvenanceTest's
        // own second test) cannot distinguish these; exact reference_id
        // provenance must.
        $f = $this->fixture();
        $this->receiveFrom($f, $f['supplierA'], '2026-09-01', 1, 1500000);
        $this->receiveFrom($f, $f['supplierB'], '2026-09-05', 1, 1500000);

        $layers = DB::table('fifo_layers')->where('inventory_item_id', $f['item']->id)->orderBy('fifo_sequence')->get();
        $this->assertCount(2, $layers);

        $supplierForFirstLayer = $this->exactSupplierFor($layers[0]);
        $supplierForSecondLayer = $this->exactSupplierFor($layers[1]);

        $this->assertSame($f['supplierA']->id, $supplierForFirstLayer->id);
        $this->assertSame($f['supplierB']->id, $supplierForSecondLayer->id);
    }

    public function test_a_remaining_layer_after_partial_consumption_still_traces_to_its_exact_supplier(): void
    {
        $f = $this->fixture();
        $this->receiveFrom($f, $f['supplierA'], '2026-09-01', 2, 1500000);
        $this->receiveFrom($f, $f['supplierB'], '2026-09-05', 2, 1500000);

        // Consume all of Supplier A's layer via a genuine ledger operation,
        // forcing consumption to cross into Supplier B's layer, then verify
        // what remains still traces correctly.
        app(InventoryLedgerService::class)->outbound($f['item'], $f['loc'], 2, 'adjustment_out', (string) Str::uuid(), null, 'consume layer A');

        $remaining = DB::table('fifo_layers')->where('inventory_item_id', $f['item']->id)->where('remaining_quantity', '>', 0)->first();
        $supplier = $this->exactSupplierFor($remaining);
        $this->assertSame($f['supplierB']->id, $supplier->id, 'the only remaining stock must trace exactly to Supplier B');
    }

    public function test_a_legacy_receipt_lacking_reference_id_reports_unknown_rather_than_a_fabricated_guess(): void
    {
        // Simulates a pre-M2.19 historical row (reference_id was never
        // written for receipts before this fix) - the query must honestly
        // report "cannot determine exact provenance", never fall back to a
        // fuzzy match that could misattribute a real supplier.
        $f = $this->fixture();
        $this->receiveFrom($f, $f['supplierA'], '2026-09-01', 1, 1500000);
        $layer = DB::table('fifo_layers')->where('inventory_item_id', $f['item']->id)->first();
        DB::table('inventory_movements')->where('id', $layer->inbound_movement_id)->update(['reference_id' => null, 'reference_type' => 'receipt']);

        $this->assertNull($this->exactSupplierFor(DB::table('fifo_layers')->find($layer->id)));
    }
}
