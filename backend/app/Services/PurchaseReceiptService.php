<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventorySupplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PurchaseReceiptService
{
    public function purchase(string $account, array $data): object
    {
        return DB::transaction(function () use ($account, $data) {
            DB::table('accounts')->where('id', $account)->lockForUpdate()->first();
            if (! empty($data['branch_id']) && ! Branch::where('account_id', $account)->whereKey($data['branch_id'])->exists()) {
                throw ValidationException::withMessages(['branch_id' => 'Invalid branch.']);
            }
            if (! empty($data['supplier_id'])) {
                $supplier = InventorySupplier::where('account_id', $account)->where('is_active', true)->when($data['branch_id'] ?? null, fn ($q, $id) => $q->visibleToBranch($id))->find($data['supplier_id']);
                if (! $supplier) {
                    throw ValidationException::withMessages(['supplier_id' => 'Supplier is not available in this scope.']);
                }
            }
            foreach ($data['lines'] as $line) {
                if (! InventoryItem::where('account_id', $account)->where('is_active', true)->whereKey($line['inventory_item_id'])->exists()) {
                    throw ValidationException::withMessages(['lines' => 'Item is not available in this scope.']);
                }
            }
            $old = DB::table('purchases')->where('account_id', $account)->where('client_request_id', $data['client_request_id'])->first();
            if ($old) {
                ReplayFields::match($old, ['branch_id' => $data['branch_id'] ?? null, 'supplier_id' => $data['supplier_id'] ?? null, 'purchase_number' => $data['purchase_number'], 'purchase_date' => $data['purchase_date'], 'currency_code' => $data['currency_code'] ?? 'IDR', 'external_reference' => $data['external_reference'] ?? null, 'notes' => $data['notes'] ?? null], [], ['purchase_date']);
                $this->assertLines(DB::table('purchase_lines')->where('purchase_id', $old->id)->get()->all(), $data['lines'], ['inventory_item_id' => 'inventory_item_id', 'ordered_quantity' => 'quantity', 'unit_cost' => 'unit_cost']);

                return $old;
            }$id = (string) Str::uuid();
            DB::table('purchases')->insert(['id' => $id, 'account_id' => $account, 'branch_id' => $data['branch_id'] ?? null, 'supplier_id' => $data['supplier_id'] ?? null, 'external_reference' => $data['external_reference'] ?? null, 'purchase_number' => $data['purchase_number'], 'purchase_date' => $data['purchase_date'], 'currency_code' => $data['currency_code'] ?? 'IDR', 'status' => 'draft', 'notes' => $data['notes'] ?? null, 'client_request_id' => $data['client_request_id'], 'created_at' => now(), 'updated_at' => now()]);
            foreach ($data['lines'] as $line) {
                DB::table('purchase_lines')->insert(['id' => (string) Str::uuid(), 'account_id' => $account, 'purchase_id' => $id, 'inventory_item_id' => $line['inventory_item_id'], 'ordered_quantity' => $line['quantity'], 'unit_cost' => $line['unit_cost'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
            }

            return DB::table('purchases')->find($id);
        });
    }

    public function receive(string $purchaseId, InventoryLocation $location, array $lines, string $requestId, ?string $personId = null, ?string $personName = null, ?string $enteredBy = null): object
    {
        return DB::transaction(function () use ($purchaseId, $location, $lines, $requestId, $personId, $personName, $enteredBy) {
            DB::table('accounts')->where('id', $location->account_id)->lockForUpdate()->first();
            $old = DB::table('receipts')->where('account_id', $location->account_id)->where('client_request_id', $requestId)->first();
            if ($old) {
                ReplayFields::match($old, ['purchase_id' => $purchaseId, 'location_id' => $location->id]);
                $storedLines = DB::table('receipt_lines')->where('receipt_id', $old->id)->get();
                $this->assertLines($storedLines->all(), $lines, ['purchase_line_id' => 'purchase_line_id', 'quantity' => 'quantity']);
                foreach ($storedLines as $storedLine) {
                    $movement = DB::table('inventory_movements')->where('account_id', $location->account_id)->where('reference_type', 'receipt_line')->where('reference_id', $storedLine->id)->first();
                    if (! $movement) {
                        throw new ConflictHttpException('Receipt provenance is incomplete.');
                    }
                    ReplayFields::match($movement, ['operational_person_id' => $personId]);
                }

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
                }$receiptLineId = (string) Str::uuid();
                DB::table('receipt_lines')->insert(['id' => $receiptLineId, 'account_id' => $location->account_id, 'receipt_id' => $rid, 'purchase_line_id' => $pl->id, 'inventory_item_id' => $pl->inventory_item_id, 'quantity' => $line['quantity'], 'unit_cost' => $pl->unit_cost, 'created_at' => now(), 'updated_at' => now()]);
                // M2.19: link this receipt's inbound movement to the exact
                // receipt_line it came from, so a FIFO layer can be traced
                // deterministically back through receipt_line -> purchase_line
                // -> purchase -> supplier, not just aggregated at the
                // purchase/receipt level.
                app(InventoryLedgerService::class)->inbound(InventoryItem::findOrFail($pl->inventory_item_id), $location, $line['quantity'], $pl->unit_cost === null ? null : (float) $pl->unit_cost, 'receipt', (string) Uuid::uuid5(Uuid::NAMESPACE_URL, $requestId.$pl->id), null, null, $personId, $personName, $enteredBy, $receiptLineId, 'receipt_line');
            }$remaining = DB::table('purchase_lines as p')->where('p.purchase_id', $purchaseId)->get()->contains(fn ($p) => (float) DB::table('receipt_lines')->where('purchase_line_id', $p->id)->sum('quantity') < (float) $p->ordered_quantity);
            DB::table('purchases')->where('id', $purchaseId)->update(['status' => $remaining ? 'partially_received' : 'received', 'updated_at' => now()]);

            return DB::table('receipts')->find($rid);
        }, 3);
    }

    private function assertLines(array $stored, array $requested, array $fields): void
    {
        $normalize = function (array $rows, bool $existing) use ($fields) {
            $result = [];
            foreach ($rows as $row) {
                $values = [];
                foreach ($fields as $column => $input) {
                    $v = $existing ? ($row->{$column} ?? null) : ($row[$input] ?? null);
                    $values[$input] = $v !== null && in_array($input, ['quantity', 'unit_cost'], true) ? (float) $v : $v;
                }
                $result[] = json_encode($values);
            }
            sort($result);

            return $result;
        };
        if ($normalize($stored, true) !== $normalize($requested, false)) {
            throw new ConflictHttpException('Request lines conflict with the stored result.');
        }
    }
}
