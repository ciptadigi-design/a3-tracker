<?php

namespace App\Services;

use App\Models\FifoAllocation;
use App\Models\FifoLayer;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class InventoryLedgerService
{
    public function balance(string $item, string $location): float
    {
        return (float) InventoryMovement::where('inventory_item_id', $item)->where('location_id', $location)->sum('quantity');
    }

    // M2.19: $referenceId/$referenceType are appended (not inserted earlier in
    // the signature) so every existing positional caller is unaffected. When
    // omitted, reference_type keeps its exact prior default (the movement
    // type string) and reference_id stays null, exactly as before. Passing
    // them lets a caller record exact provenance - e.g. PurchaseReceiptService
    // links a receipt's inbound movement to its receipt_line, so a FIFO layer
    // can be traced deterministically: fifo_layers.inbound_movement_id ->
    // inventory_movements.reference_id (where reference_type='receipt_line')
    // -> receipt_lines.purchase_line_id -> purchase_lines.purchase_id ->
    // purchases.supplier_id. reference_id/reference_type already existed on
    // this table and are the same polymorphic-reference mechanism outbound()
    // already exposes - no schema change, no new column.
    public function inbound(InventoryItem $item, InventoryLocation $location, float $quantity, ?float $cost, string $type, string $requestId, ?string $reason = null, $occurredAt = null, ?string $personId = null, ?string $personName = null, ?string $enteredBy = null, ?string $referenceId = null, ?string $referenceType = null): InventoryMovement
    {
        return DB::transaction(function () use ($item, $location, $quantity, $cost, $type, $requestId, $reason, $occurredAt, $personId, $personName, $enteredBy, $referenceId, $referenceType) {
            $this->validateScope($item, $location);
            $item->newQuery()->whereKey($item->id)->lockForUpdate()->first();
            if ($quantity <= 0) {
                throw new ConflictHttpException('quantity must be positive');
            }$old = InventoryMovement::where('account_id', $item->account_id)->where('client_request_id', $requestId)->where('movement_type', $type)->first();
            if ($old) {
                return $old;
            }$m = InventoryMovement::create(['account_id' => $item->account_id, 'inventory_item_id' => $item->id, 'location_id' => $location->id, 'movement_type' => $type, 'quantity' => $quantity, 'occurred_at' => $occurredAt ?? now(), 'reference_type' => $referenceType ?? $type, 'reference_id' => $referenceId, 'reason' => $reason, 'entered_by' => $enteredBy, 'operational_person_id' => $personId, 'operational_person_name_snapshot' => $personName, 'client_request_id' => $requestId]);
            $sequence = ((int) FifoLayer::where('inventory_item_id', $item->id)->where('location_id', $location->id)->max('fifo_sequence')) + 1;
            FifoLayer::create(['account_id' => $item->account_id, 'inventory_item_id' => $item->id, 'location_id' => $location->id, 'inbound_movement_id' => $m->id, 'source_type' => $type, 'original_quantity' => $quantity, 'remaining_quantity' => $quantity, 'unit_cost' => $cost, 'effective_at' => $m->occurred_at, 'fifo_sequence' => $sequence]);

            return $m;
        }, 3);
    }

    public function outbound(InventoryItem $item, InventoryLocation $location, float $quantity, string $type, string $requestId, ?string $referenceId = null, ?string $reason = null, ?string $transferId = null, ?string $personId = null, ?string $personName = null, ?string $enteredBy = null): InventoryMovement
    {
        return DB::transaction(function () use ($item, $location, $quantity, $type, $requestId, $referenceId, $reason, $transferId, $personId, $personName, $enteredBy) {
            $this->validateScope($item, $location);
            $item->newQuery()->whereKey($item->id)->lockForUpdate()->first();
            // M2.19: mirrors inbound()'s own idempotency check, and matches the
            // scope of the movement_request_leg_uq unique index that has existed
            // on this table since its original migration (account_id +
            // client_request_id + movement_type + location_id - location_id is
            // part of the key so transfer_out/transfer_in can share one
            // client_request_id at their two different locations). The database
            // was already preventing a true duplicate row; without this check,
            // a retry either crashed on that unique-constraint violation or, if
            // intervening activity had since dropped the balance below the
            // retried quantity, incorrectly failed with "insufficient stock" on
            // an operation that had already completed. This check must run
            // BEFORE the balance check below for exactly that second reason: a
            // legitimate retry reports the fact that already happened, it does
            // not re-validate against whatever the current balance happens to
            // be now.
            $old = InventoryMovement::where('account_id', $item->account_id)->where('client_request_id', $requestId)->where('movement_type', $type)->where('location_id', $location->id)->first();
            if ($old) {
                return $old;
            }
            if ($quantity <= 0 || $this->balance($item->id, $location->id) < $quantity) {
                throw new ConflictHttpException('insufficient stock');
            }$m = InventoryMovement::create(['account_id' => $item->account_id, 'inventory_item_id' => $item->id, 'location_id' => $location->id, 'movement_type' => $type, 'quantity' => -$quantity, 'occurred_at' => now(), 'reference_type' => $type === 'replacement_consumption' ? 'component_replacement' : $type, 'reference_id' => $referenceId, 'reason' => $reason, 'entered_by' => $enteredBy, 'operational_person_id' => $personId, 'operational_person_name_snapshot' => $personName, 'client_request_id' => $requestId, 'transfer_id' => $transferId]);
            $need = $quantity;
            $order = 1;
            $layers = FifoLayer::where('inventory_item_id', $item->id)->where('location_id', $location->id)->where('remaining_quantity', '>', 0)->orderBy('fifo_sequence')->orderBy('effective_at')->orderBy('id')->lockForUpdate()->get();
            foreach ($layers as $l) {
                $take = min($need, (float) $l->remaining_quantity);
                FifoAllocation::create(['account_id' => $item->account_id, 'outbound_movement_id' => $m->id, 'fifo_layer_id' => $l->id, 'quantity' => $take, 'unit_cost' => $l->unit_cost, 'allocated_cost' => $l->unit_cost === null ? null : $take * (float) $l->unit_cost, 'allocation_order' => $order++]);
                $l->decrement('remaining_quantity', $take);
                $need -= $take;
                if ($need <= 0) {
                    break;
                }
            }if ($need > 0) {
                throw new ConflictHttpException('incomplete FIFO cost basis');
            }

            return $m;
        }, 3);
    }

    public function transfer(InventoryItem $item, InventoryLocation $from, InventoryLocation $to, float $quantity, string $requestId, ?string $personId = null, ?string $personName = null, ?string $enteredBy = null): array
    {
        if ($from->id === $to->id) {
            throw new ConflictHttpException('locations must differ');
        }

        return DB::transaction(function () use ($item, $from, $to, $quantity, $requestId, $personId, $personName, $enteredBy) {
            $item->newQuery()->whereKey($item->id)->lockForUpdate()->first();
            $existingOut = InventoryMovement::where('account_id', $item->account_id)->where('client_request_id', $requestId)->where('movement_type', 'transfer_out')->lockForUpdate()->first();
            $existingIn = InventoryMovement::where('account_id', $item->account_id)->where('client_request_id', $requestId)->where('movement_type', 'transfer_in')->lockForUpdate()->first();
            if ($existingOut && $existingIn) {
                return [$existingOut, $existingIn];
            }
            $transfer = (string) Str::uuid();
            $out = $this->outbound($item, $from, $quantity, 'transfer_out', $requestId, null, 'stock transfer', $transfer, $personId, $personName, $enteredBy);
            $in = InventoryMovement::create(['account_id' => $item->account_id, 'inventory_item_id' => $item->id, 'location_id' => $to->id, 'movement_type' => 'transfer_in', 'quantity' => $quantity, 'occurred_at' => $out->occurred_at, 'reference_type' => 'stock_transfer', 'entered_by' => $enteredBy, 'operational_person_id' => $personId, 'operational_person_name_snapshot' => $personName, 'client_request_id' => $requestId, 'transfer_id' => $transfer]);
            $sequence = ((int) FifoLayer::where('inventory_item_id', $item->id)->where('location_id', $to->id)->max('fifo_sequence'));
            foreach (FifoAllocation::where('outbound_movement_id', $out->id)->orderBy('allocation_order')->get() as $a) {
                FifoLayer::create(['account_id' => $item->account_id, 'inventory_item_id' => $item->id, 'location_id' => $to->id, 'inbound_movement_id' => $in->id, 'source_type' => 'transfer_in', 'original_quantity' => $a->quantity, 'remaining_quantity' => $a->quantity, 'unit_cost' => $a->unit_cost, 'effective_at' => $in->occurred_at, 'origin_layer_id' => $a->fifo_layer_id, 'fifo_sequence' => ++$sequence]);
            }

            return [$out, $in];
        }, 3);
    }

    private function validateScope(InventoryItem $item, InventoryLocation $location): void
    {
        if ($item->account_id !== $location->account_id || ($item->is_active === false) || ($location->is_active === false)) {
            throw new ConflictHttpException('active item and location in same account required');
        }
    }
}
