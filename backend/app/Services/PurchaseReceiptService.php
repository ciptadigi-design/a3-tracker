<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PurchaseReceiptService
{
    public function purchase(string $account, array $data): object
    {
        return DB::transaction(function () use ($account, $data) {
            $old = DB::table('purchases')->where('account_id', $account)->where('client_request_id', $data['client_request_id'])->first();
            if ($old) {
                return $old;
            }$id = (string) Str::uuid();
            DB::table('purchases')->insert(['id' => $id, 'account_id' => $account, 'branch_id' => $data['branch_id'] ?? null, 'supplier_id' => $data['supplier_id'] ?? null, 'external_reference' => $data['external_reference'] ?? null, 'purchase_number' => $data['purchase_number'], 'purchase_date' => $data['purchase_date'], 'currency_code' => $data['currency_code'] ?? 'IDR', 'status' => 'draft', 'notes' => $data['notes'] ?? null, 'client_request_id' => $data['client_request_id'], 'created_at' => now(), 'updated_at' => now()]);
            foreach ($data['lines'] as $line) {
                DB::table('purchase_lines')->insert(['id' => (string) Str::uuid(), 'account_id' => $account, 'purchase_id' => $id, 'inventory_item_id' => $line['inventory_item_id'], 'ordered_quantity' => $line['quantity'], 'unit_cost' => $line['unit_cost'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            }

            return DB::table('purchases')->find($id);
        });
    }

    public function receive(string $purchaseId, InventoryLocation $location, array $lines, string $requestId): object
    {
        return DB::transaction(function () use ($purchaseId, $location, $lines, $requestId) {
            $old = DB::table('receipts')->where('account_id', $location->account_id)->where('client_request_id', $requestId)->first();
            if ($old) {
                return $old;
            }$purchase = DB::table('purchases')->where('id', $purchaseId)->where('account_id', $location->account_id)->lockForUpdate()->first();
            if (! $purchase) {
                throw new ConflictHttpException('purchase not found in location account');
            }$rid = (string) Str::uuid();
            DB::table('receipts')->insert(['id' => $rid, 'account_id' => $location->account_id, 'purchase_id' => $purchaseId, 'location_id' => $location->id, 'received_at' => now(), 'client_request_id' => $requestId, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($lines as $line) {
                $pl = DB::table('purchase_lines')->where('id', $line['purchase_line_id'])->where('purchase_id', $purchaseId)->first();
                if (! $pl) {
                    throw new ConflictHttpException('purchase line not found');
                }
                $received = (float) DB::table('receipt_lines')->where('purchase_line_id', $pl->id)->sum('quantity');
                if ($received + $line['quantity'] > (float) $pl->ordered_quantity) {
                    throw new ConflictHttpException('receipt exceeds ordered quantity');
                }DB::table('receipt_lines')->insert(['id' => (string) Str::uuid(), 'account_id' => $location->account_id, 'receipt_id' => $rid, 'purchase_line_id' => $pl->id, 'inventory_item_id' => $pl->inventory_item_id, 'quantity' => $line['quantity'], 'unit_cost' => $pl->unit_cost, 'created_at' => now(), 'updated_at' => now()]);
                app(InventoryLedgerService::class)->inbound(InventoryItem::findOrFail($pl->inventory_item_id), $location, $line['quantity'], $pl->unit_cost === null ? null : (float) $pl->unit_cost, 'receipt', (string) Uuid::uuid5(Uuid::NAMESPACE_URL, $requestId.$pl->id));
            }$remaining = DB::table('purchase_lines as p')->where('p.purchase_id', $purchaseId)->get()->contains(fn ($p) => (float) DB::table('receipt_lines')->where('purchase_line_id', $p->id)->sum('quantity') < (float) $p->ordered_quantity);
            DB::table('purchases')->where('id', $purchaseId)->update(['status' => $remaining ? 'partially_received' : 'received', 'updated_at' => now()]);

            return DB::table('receipts')->find($rid);
        }, 3);
    }
}
