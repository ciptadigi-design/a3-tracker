<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Builds frontend-ready purchase and purchase-line view models from raw
 * `purchases` / `purchase_lines` / `receipt_lines` rows.
 *
 * The Purchasing UI (src/features/inventory/PurchasingPanel.jsx and
 * PurchasingDialogs.jsx) never renders raw table columns: it expects a
 * pre-aggregated shape (purchase_id, purchase_total, line_count,
 * supplier_name_snapshot, receiving_progress_percent,
 * fully_received_line_count, ...) that historically was produced by a
 * Supabase Postgres view (`inventory_purchase_summary` /
 * `inventory_purchase_line_status`). The Laravel backend previously
 * returned raw DB::table('purchases')->get() rows with none of those
 * derived fields, which produced "RpNaN" totals, blank item/line counts,
 * a garbled receiving badge, and — for purchases without a supplier_id
 * (e.g. legacy imports) — an undefined supplier name.
 *
 * This builder is pure (no DB access) so it can be unit tested directly
 * against fixture rows, including the 161 LEGACY-* purchase headers /
 * lines already present in production.
 */
class PurchaseSummaryBuilder
{
    /**
     * @param  Collection  $purchases  rows from `purchases`
     * @param  Collection  $lines  rows from `purchase_lines`
     * @param  Collection  $receiptLines  rows from `receipt_lines`
     * @param  Collection  $items  Eloquent/stdClass rows with id, name, sku, unit
     * @param  Collection  $suppliers  Eloquent/stdClass rows with id, name, supplier_code (aka `code`)
     * @return array{purchases: Collection, lines: Collection}
     */
    public function build(Collection $purchases, Collection $lines, Collection $receiptLines, Collection $items, Collection $suppliers): array
    {
        $itemsById = $items->keyBy('id');
        $suppliersById = $suppliers->keyBy('id');

        $receivedByLine = $receiptLines
            ->groupBy('purchase_line_id')
            ->map(fn ($group) => (float) $group->sum(fn ($receiptLine) => (float) ($receiptLine->quantity ?? 0)));

        $lineSummaries = $lines->map(function ($line) use ($itemsById, $receivedByLine) {
            $item = $itemsById->get($line->inventory_item_id);
            $ordered = (float) ($line->ordered_quantity ?? 0);
            $received = (float) ($receivedByLine->get($line->id) ?? 0);
            $unitPrice = $line->unit_cost === null ? 0.0 : (float) $line->unit_cost;

            return (object) [
                'purchase_line_id' => $line->id,
                'purchase_id' => $line->purchase_id,
                'account_id' => $line->account_id,
                'inventory_item_id' => $line->inventory_item_id,
                'item_name_snapshot' => $item->name ?? 'Unknown item',
                'item_sku_snapshot' => $item->sku ?? null,
                'unit_snapshot' => $item->unit ?? 'pcs',
                'ordered_quantity' => $ordered,
                'received_quantity' => $received,
                'remaining_quantity' => max(0.0, $ordered - $received),
                'unit_price' => $unitPrice,
                'line_total' => round($ordered * $unitPrice, 2),
                'notes' => $line->notes ?? null,
            ];
        });

        $linesByPurchase = $lineSummaries->groupBy('purchase_id');

        $purchaseSummaries = $purchases->map(function ($purchase) use ($linesByPurchase, $suppliersById) {
            $purchaseLines = $linesByPurchase->get($purchase->id, collect());
            $lineCount = $purchaseLines->count();
            $total = round((float) $purchaseLines->sum('line_total'), 2);
            $fullyReceivedLineCount = $purchaseLines->filter(fn ($line) => (float) $line->remaining_quantity <= 0.0 && (float) $line->ordered_quantity > 0.0)->count();
            $receivingProgressPercent = $lineCount > 0 ? round(($fullyReceivedLineCount / $lineCount) * 100, 2) : 0.0;
            $supplier = $purchase->supplier_id ? $suppliersById->get($purchase->supplier_id) : null;
            $isLegacyImport = is_string($purchase->notes ?? null) && str_starts_with($purchase->notes, 'LEGACY_IMPORT');

            return (object) [
                'purchase_id' => $purchase->id,
                'account_id' => $purchase->account_id,
                'branch_id' => $purchase->branch_id ?? null,
                'purchase_number' => $purchase->purchase_number,
                'purchase_date' => $purchase->purchase_date,
                'currency_code' => $purchase->currency_code ?? 'IDR',
                'status' => $purchase->status,
                'notes' => $purchase->notes ?? null,
                'is_legacy_import' => $isLegacyImport,
                'supplier_id' => $purchase->supplier_id ?? null,
                'supplier_name_snapshot' => $supplier->name ?? ($isLegacyImport ? 'Historical import (no supplier on record)' : 'Unknown supplier'),
                'supplier_code_snapshot' => $supplier->supplier_code ?? $supplier->code ?? null,
                'supplier_reference' => $purchase->external_reference ?? null,
                'line_count' => $lineCount,
                'purchase_total' => $total,
                'fully_received_line_count' => $fullyReceivedLineCount,
                'receiving_progress_percent' => $receivingProgressPercent,
                'client_request_id' => $purchase->client_request_id ?? null,
                'created_at' => $purchase->created_at ?? null,
                'updated_at' => $purchase->updated_at ?? null,
            ];
        });

        return ['purchases' => $purchaseSummaries->values(), 'lines' => $lineSummaries->values()];
    }
}
