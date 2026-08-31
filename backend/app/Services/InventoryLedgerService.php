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

    public function inbound(InventoryItem $item, InventoryLocation $location, float $quantity, ?float $cost, string $type, string $requestId, ?string $reason = null, $occurredAt = null): InventoryMovement
    {
        return DB::transaction(function () use ($item, $location, $quantity, $cost, $type, $requestId, $reason, $occurredAt) {
            $this->validateScope($item, $location);
            $item->newQuery()->whereKey($item->id)->lockForUpdate()->first();
            if ($quantity <= 0) {
                throw new ConflictHttpException('quantity must be positive');
            }$old = InventoryMovement::where('account_id', $item->account_id)->where('client_request_id', $requestId)->where('movement_type', $type)->first();
            if ($old) {
                return $old;
            }$m = InventoryMovement::create(['account_id' => $item->account_id, 'inventory_item_id' => $item->id, 'location_id' => $location->id, 'movement_type' => $type, 'quantity' => $quantity, 'occurred_at' => $occurredAt ?? now(), 'reference_type' => $type, 'reason' => $reason, 'client_request_id' => $requestId]);
            $sequence = ((int) FifoLayer::where('inventory_item_id', $item->id)->where('location_id', $location->id)->max('fifo_sequence')) + 1;
            FifoLayer::create(['account_id' => $item->account_id, 'inventory_item_id' => $item->id, 'location_id' => $location->id, 'inbound_movement_id' => $m->id, 'source_type' => $type, 'original_quantity' => $quantity, 'remaining_quantity' => $quantity, 'unit_cost' => $cost, 'effective_at' => $m->occurred_at, 'fifo_sequence' => $sequence]);

            return $m;
        }, 3);
    }

    public function outbound(InventoryItem $item, InventoryLocation $location, float $quantity, string $type, string $requestId, ?string $referenceId = null, ?string $reason = null, ?string $transferId = null): InventoryMovement
    {
        return DB::transaction(function () use ($item, $location, $quantity, $type, $requestId, $referenceId, $reason, $transferId) {
            $this->validateScope($item, $location);
            $item->newQuery()->whereKey($item->id)->lockForUpdate()->first();
            if ($quantity <= 0 || $this->balance($item->id, $location->id) < $quantity) {
                throw new ConflictHttpException('insufficient stock');
            }$m = InventoryMovement::create(['account_id' => $item->account_id, 'inventory_item_id' => $item->id, 'location_id' => $location->id, 'movement_type' => $type, 'quantity' => -$quantity, 'occurred_at' => now(), 'reference_type' => $type === 'replacement_consumption' ? 'component_replacement' : $type, 'reference_id' => $referenceId, 'reason' => $reason, 'client_request_id' => $requestId, 'transfer_id' => $transferId]);
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

    public function transfer(InventoryItem $item, InventoryLocation $from, InventoryLocation $to, float $quantity, string $requestId): array
    {
        if ($from->id === $to->id) {
            throw new ConflictHttpException('locations must differ');
        }

        return DB::transaction(function () use ($item, $from, $to, $quantity, $requestId) {
            $item->newQuery()->whereKey($item->id)->lockForUpdate()->first();
            $existingOut = InventoryMovement::where('account_id', $item->account_id)->where('client_request_id', $requestId)->where('movement_type', 'transfer_out')->lockForUpdate()->first();
            $existingIn = InventoryMovement::where('account_id', $item->account_id)->where('client_request_id', $requestId)->where('movement_type', 'transfer_in')->lockForUpdate()->first();
            if ($existingOut && $existingIn) {
                return [$existingOut, $existingIn];
            }
            $transfer = (string) Str::uuid();
            $out = $this->outbound($item, $from, $quantity, 'transfer_out', $requestId, null, 'stock transfer', $transfer);
            $in = InventoryMovement::create(['account_id' => $item->account_id, 'inventory_item_id' => $item->id, 'location_id' => $to->id, 'movement_type' => 'transfer_in', 'quantity' => $quantity, 'occurred_at' => $out->occurred_at, 'reference_type' => 'stock_transfer', 'client_request_id' => $requestId, 'transfer_id' => $transfer]);
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
