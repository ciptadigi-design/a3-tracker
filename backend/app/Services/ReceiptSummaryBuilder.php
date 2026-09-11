<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Ramsey\Uuid\Uuid;

/**
 * Builds frontend-ready receipt LINE view models from raw `receipts` /
 * `receipt_lines` rows.
 *
 * M2.17.5: the Receiving tab (src/features/inventory/PurchasingPanel.jsx's
 * ReceiptList) expects a receipt-LINE-level projection (item_name_snapshot,
 * receipt_number, purchase_number_snapshot, supplier_name_snapshot,
 * unit_price_snapshot, acquisition_value, location_name,
 * operational_person_name_snapshot, ...). InventoryController@workspace was
 * instead returning raw `receipts` table HEADER rows (id, purchase_id,
 * location_id, received_at, reference, ...) - none of the fields the UI reads
 * exist on that shape, which is exactly the same class of bug M2.17's
 * PurchaseSummaryBuilder fixed for the Purchases list (commit 02816d4): every
 * receipt rendered "+0" received, a blank Purchase/Location, and "RpNaN" for
 * acquisition price.
 *
 * PIC is not stored on receipt_lines itself (only on the inventory_movement
 * that PurchaseReceiptService::receive() creates for each line) - the two are
 * linked by a deterministic UUIDv5 the write path already derives
 * (Uuid::uuid5(NAMESPACE_URL, $receipt->client_request_id.$line->purchase_line_id))
 * specifically so retries are idempotent. Recomputing that same UUID here to
 * look the movement up requires no schema change and no fragile
 * timestamp/quantity matching.
 */
class ReceiptSummaryBuilder
{
    /**
     * @param  Collection  $receipts  rows from `receipts`
     * @param  Collection  $receiptLines  rows from `receipt_lines`
     * @param  Collection  $purchases  rows from `purchases` (for purchase_number/supplier_id)
     * @param  Collection  $items  Eloquent/stdClass rows with id, name, sku, unit
     * @param  Collection  $supplierLookup  Eloquent/stdClass rows with id, name (unfiltered by branch - see InventoryController's own comment on why)
     * @param  Collection  $locationLookup  Eloquent/stdClass rows with id, name (unfiltered by is_active, so an archived location still resolves)
     * @param  Collection  $receiptMovements  `inventory_movements` rows with movement_type='receipt' for this account
     * @return Collection receipt-line view models, newest first
     */
    public function build(Collection $receipts, Collection $receiptLines, Collection $purchases, Collection $items, Collection $supplierLookup, Collection $locationLookup, Collection $receiptMovements): Collection
    {
        $receiptsById = $receipts->keyBy('id');
        $purchasesById = $purchases->keyBy('id');
        $itemsById = $items->keyBy('id');
        $suppliersById = $supplierLookup->keyBy('id');
        $locationsById = $locationLookup->keyBy('id');
        $movementsByRequestId = $receiptMovements->keyBy('client_request_id');

        return $receiptLines->map(function ($line) use ($receiptsById, $purchasesById, $itemsById, $suppliersById, $locationsById, $movementsByRequestId) {
            $receipt = $receiptsById->get($line->receipt_id);
            $purchase = $receipt?->purchase_id ? $purchasesById->get($receipt->purchase_id) : null;
            $item = $itemsById->get($line->inventory_item_id);
            $supplier = $purchase?->supplier_id ? $suppliersById->get($purchase->supplier_id) : null;
            $location = $receipt?->location_id ? $locationsById->get($receipt->location_id) : null;
            $movementRequestId = $receipt !== null
                ? (string) Uuid::uuid5(Uuid::NAMESPACE_URL, $receipt->client_request_id.$line->purchase_line_id)
                : null;
            $movement = $movementRequestId !== null ? $movementsByRequestId->get($movementRequestId) : null;
            $unitPrice = $line->unit_cost === null ? null : (float) $line->unit_cost;
            $quantity = (float) $line->quantity;

            return (object) [
                'receipt_line_id' => $line->id,
                'receipt_id' => $line->receipt_id,
                'purchase_id' => $receipt?->purchase_id,
                'inventory_item_id' => $line->inventory_item_id,
                'item_name_snapshot' => $item->name ?? 'Unknown item',
                'item_sku_snapshot' => $item->sku ?? null,
                'unit_snapshot' => $item->unit ?? 'pcs',
                'quantity' => $quantity,
                'receipt_number' => $receipt?->reference ?? null,
                'purchase_number_snapshot' => $purchase->purchase_number ?? null,
                'supplier_name_snapshot' => $supplier->name ?? null,
                'unit_price_snapshot' => $unitPrice,
                'acquisition_value' => $unitPrice === null ? null : round($unitPrice * $quantity, 2),
                'location_id' => $receipt?->location_id,
                'location_name' => $location->name ?? 'Unknown location',
                'operational_person_name_snapshot' => $movement->operational_person_name_snapshot ?? null,
                'received_at' => $receipt?->received_at,
            ];
        })->sortByDesc('received_at')->values();
    }
}
