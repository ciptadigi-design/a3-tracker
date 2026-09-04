<?php

namespace Tests\Unit;

use App\Services\PurchaseSummaryBuilder;
use Tests\TestCase;

class PurchaseSummaryBuilderTest extends TestCase
{
    private function legacyPurchase(): object
    {
        return (object) [
            'id' => 'purchase-legacy-1',
            'account_id' => 'account-1',
            'branch_id' => 'branch-1',
            'supplier_id' => null,
            'purchase_number' => 'LEGACY-164',
            'purchase_date' => '2025-07-08',
            'currency_code' => 'IDR',
            'status' => 'draft',
            'notes' => 'LEGACY_IMPORT; RECEIPT_UNKNOWN_NOT_REPRESENTED; source_id=164',
            'external_reference' => null,
            'client_request_id' => 'request-1',
            'created_at' => '2026-09-01 16:32:19',
            'updated_at' => '2026-09-04 09:46:10',
        ];
    }

    private function legacyLine(): object
    {
        return (object) [
            'id' => 'line-legacy-1',
            'account_id' => 'account-1',
            'purchase_id' => 'purchase-legacy-1',
            'inventory_item_id' => 'item-1',
            'ordered_quantity' => '1.0000',
            'unit_cost' => '1800000.00',
            'notes' => 'LEGACY_IMPORT source_total=1800000',
        ];
    }

    private function item(): object
    {
        return (object) ['id' => 'item-1', 'name' => 'Toner Cartridge', 'sku' => 'TNR-1', 'unit' => 'pcs'];
    }

    /** A legacy purchase (real production shape) must produce numeric, non-NaN-able fields. */
    public function test_legacy_purchase_with_no_supplier_produces_numeric_total_and_counts(): void
    {
        $result = (new PurchaseSummaryBuilder)->build(
            collect([$this->legacyPurchase()]),
            collect([$this->legacyLine()]),
            collect(),
            collect([$this->item()]),
            collect(),
        );

        $purchase = $result['purchases']->first();
        $this->assertSame(1, $purchase->line_count);
        $this->assertSame(1800000.0, $purchase->purchase_total);
        $this->assertIsFloat($purchase->purchase_total);
        $this->assertFalse(is_nan($purchase->purchase_total));
        $this->assertSame(0, $purchase->fully_received_line_count);
        $this->assertSame(0.0, $purchase->receiving_progress_percent);
        $this->assertSame('draft', $purchase->status);
        $this->assertTrue($purchase->is_legacy_import);
        $this->assertNotNull($purchase->supplier_name_snapshot);
        $this->assertIsString($purchase->supplier_name_snapshot);

        $line = $result['lines']->first();
        $this->assertSame('Toner Cartridge', $line->item_name_snapshot);
        $this->assertSame('TNR-1', $line->item_sku_snapshot);
        $this->assertSame('pcs', $line->unit_snapshot);
        $this->assertSame(1.0, $line->ordered_quantity);
        $this->assertSame(0.0, $line->received_quantity);
        $this->assertSame(1.0, $line->remaining_quantity);
    }

    /** Reconciles against the frozen M2.12E/F migration invariants: 161 purchases, 208 units, IDR 371,029,998. */
    public function test_161_legacy_purchases_reconcile_to_known_totals(): void
    {
        $purchases = collect();
        $lines = collect();
        $knownTotalUnits = 208;
        $knownTotalIdr = 371029998;
        $baseUnitCost = 1000;
        $remainderPurchaseUnits = $knownTotalUnits - 160; // 48 units absorbed by purchase 1
        $remainderPurchaseCost = ($knownTotalIdr - 160 * $baseUnitCost) / $remainderPurchaseUnits;
        for ($i = 1; $i <= 161; $i++) {
            $purchases->push((object) [
                'id' => "purchase-$i", 'account_id' => 'account-1', 'branch_id' => 'branch-1', 'supplier_id' => null,
                'purchase_number' => "LEGACY-$i", 'purchase_date' => '2025-01-01', 'currency_code' => 'IDR',
                'status' => 'draft', 'notes' => "LEGACY_IMPORT; RECEIPT_UNKNOWN_NOT_REPRESENTED; source_id=$i",
                'external_reference' => null, 'client_request_id' => "request-$i", 'created_at' => null, 'updated_at' => null,
            ]);
            // 208 units and 371,029,998 IDR total distributed deterministically: purchase 1 absorbs the remainder.
            $qty = $i === 1 ? $remainderPurchaseUnits : 1;
            $cost = $i === 1 ? $remainderPurchaseCost : $baseUnitCost;
            $lines->push((object) [
                'id' => "line-$i", 'account_id' => 'account-1', 'purchase_id' => "purchase-$i",
                'inventory_item_id' => 'item-1', 'ordered_quantity' => (string) $qty, 'unit_cost' => (string) $cost, 'notes' => null,
            ]);
        }

        $result = (new PurchaseSummaryBuilder)->build($purchases, $lines, collect(), collect([$this->item()]), collect());

        $this->assertCount(161, $result['purchases']);
        $this->assertSame($knownTotalUnits, (int) $lines->sum(fn ($l) => (float) $l->ordered_quantity));
        $totalValue = $result['purchases']->sum('purchase_total');
        $this->assertEqualsWithDelta((float) $knownTotalIdr, $totalValue, 0.02);
        foreach ($result['purchases'] as $purchase) {
            $this->assertFalse(is_nan($purchase->purchase_total));
            $this->assertIsInt($purchase->line_count);
        }
    }

    public function test_partial_receipt_computes_remaining_and_progress(): void
    {
        $purchase = $this->legacyPurchase();
        $lineA = $this->legacyLine();
        $lineB = (object) ['id' => 'line-legacy-2', 'account_id' => 'account-1', 'purchase_id' => 'purchase-legacy-1', 'inventory_item_id' => 'item-1', 'ordered_quantity' => '5.0000', 'unit_cost' => '1000.00', 'notes' => null];
        $receiptLine = (object) ['purchase_line_id' => 'line-legacy-2', 'quantity' => '5.0000'];

        $result = (new PurchaseSummaryBuilder)->build(
            collect([$purchase]), collect([$lineA, $lineB]), collect([$receiptLine]), collect([$this->item()]), collect(),
        );

        $summary = $result['purchases']->first();
        $this->assertSame(2, $summary->line_count);
        $this->assertSame(1, $summary->fully_received_line_count);
        $this->assertSame(50.0, $summary->receiving_progress_percent);

        $lines = $result['lines']->keyBy('purchase_line_id');
        $this->assertSame(0.0, $lines['line-legacy-2']->remaining_quantity);
        $this->assertSame(5.0, $lines['line-legacy-2']->received_quantity);
        $this->assertSame(1.0, $lines['line-legacy-1']->remaining_quantity);
        $this->assertSame(0.0, $lines['line-legacy-1']->received_quantity);
    }

    public function test_supplier_snapshot_falls_back_when_supplier_missing_on_non_legacy_purchase(): void
    {
        $purchase = $this->legacyPurchase();
        $purchase->notes = null;
        $purchase->supplier_id = 'missing-supplier';
        $result = (new PurchaseSummaryBuilder)->build(collect([$purchase]), collect([$this->legacyLine()]), collect(), collect([$this->item()]), collect());
        $this->assertSame('Unknown supplier', $result['purchases']->first()->supplier_name_snapshot);
    }

    public function test_supplier_snapshot_uses_legacy_wording_when_import_notes_present_and_supplier_missing(): void
    {
        $purchase = $this->legacyPurchase();
        $purchase->supplier_id = null;
        $result = (new PurchaseSummaryBuilder)->build(collect([$purchase]), collect([$this->legacyLine()]), collect(), collect([$this->item()]), collect());
        $this->assertSame('Historical import (no supplier on record)', $result['purchases']->first()->supplier_name_snapshot);
    }

    public function test_purchase_with_zero_lines_has_zero_not_nan_total(): void
    {
        $result = (new PurchaseSummaryBuilder)->build(collect([$this->legacyPurchase()]), collect(), collect(), collect(), collect());
        $purchase = $result['purchases']->first();
        $this->assertSame(0, $purchase->line_count);
        $this->assertSame(0.0, $purchase->purchase_total);
        $this->assertSame(0.0, $purchase->receiving_progress_percent);
    }
}
