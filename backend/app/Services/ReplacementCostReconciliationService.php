<?php

namespace App\Services;

use App\Models\ComponentReplacement;
use App\Models\FifoAllocation;
use App\Models\FifoLayer;
use App\Models\InventoryMovement;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Controlled administrative reconciliation for a historical component
 * replacement whose inventory cost was originally recorded as unknown
 * (fifo_layers.unit_cost / fifo_allocations.allocated_cost /
 * component_replacements.consumed_cost all NULL), once the business
 * supplies the real authoritative unit cost after the fact.
 *
 * This is NOT part of the normal operator Replace workflow - it exists
 * because neither ReplaceMachineComponent nor InventoryLedgerService ever
 * recalculate allocated_cost/consumed_cost after creation (both are
 * write-once snapshots), so a later-discovered real cost has no supported
 * path back into already-recorded history without this.
 *
 * inventory_movements are NEVER touched - InventoryMovement::booted()
 * already throws on any update/delete attempt, so this is enforced at the
 * model layer too, not just by this service choosing not to call it.
 *
 * Deliberately solves only the deterministic single-FIFO-allocation case;
 * a replacement whose movement drew from more than one layer fails closed
 * with a clear message rather than guessing a multi-layer cost-split rule.
 */
class ReplacementCostReconciliationService
{
    /**
     * @return array{status:string, replacement_id:string, inventory_item_sku:string, inventory_item_name:string,
     *     inventory_movement_id:string, fifo_layer_id:string, fifo_allocation_id:string, quantity:float, unit_cost:float,
     *     before:array{fifo_layer_unit_cost:?float, allocation_allocated_cost:?float, replacement_consumed_cost:?float},
     *     after:array{fifo_layer_unit_cost:?float, allocation_allocated_cost:?float, replacement_consumed_cost:?float}}
     */
    public function reconcile(string $replacementId, float $unitCost, bool $apply): array
    {
        if ($unitCost <= 0) {
            throw new ConflictHttpException('unit cost must be positive');
        }

        $replacement = ComponentReplacement::find($replacementId);
        if (! $replacement) {
            throw new ConflictHttpException("component_replacements row {$replacementId} not found");
        }
        if ($replacement->inventory_source !== 'inventory' || ! $replacement->inventory_movement_id) {
            throw new ConflictHttpException('replacement is not inventory-backed - nothing to reconcile');
        }

        $movement = InventoryMovement::find($replacement->inventory_movement_id);
        if (! $movement) {
            throw new ConflictHttpException('the replacement\'s inventory_movement_id does not resolve to a real movement');
        }
        if ($movement->movement_type !== 'replacement_consumption') {
            throw new ConflictHttpException("movement is a '{$movement->movement_type}', not 'replacement_consumption' - refusing to reconcile a non-replacement movement");
        }
        if ((string) $movement->inventory_item_id !== (string) $replacement->inventory_item_id) {
            throw new ConflictHttpException('movement.inventory_item_id does not match replacement.inventory_item_id');
        }
        if ((string) $movement->account_id !== (string) $replacement->account_id) {
            throw new ConflictHttpException('movement.account_id does not match replacement.account_id');
        }

        $allocations = FifoAllocation::where('outbound_movement_id', $movement->id)->get();
        if ($allocations->count() !== 1) {
            throw new ConflictHttpException("expected exactly one FIFO allocation for this movement, found {$allocations->count()} - multi-allocation reconciliation is not supported yet");
        }
        $allocation = $allocations->first();
        if ((float) $allocation->quantity <= 0) {
            throw new ConflictHttpException('FIFO allocation quantity must be positive');
        }

        $layer = FifoLayer::find($allocation->fifo_layer_id);
        if (! $layer) {
            throw new ConflictHttpException('the allocation\'s fifo_layer_id does not resolve to a real layer');
        }
        if ((string) $layer->inventory_item_id !== (string) $movement->inventory_item_id) {
            throw new ConflictHttpException('fifo_layers.inventory_item_id does not match the movement\'s inventory item');
        }
        if ((string) $layer->account_id !== (string) $movement->account_id) {
            throw new ConflictHttpException('fifo_layers.account_id does not match the movement\'s account');
        }

        $quantity = (float) $allocation->quantity;
        $expectedCost = round($quantity * $unitCost, 2);

        $before = [
            'fifo_layer_unit_cost' => $layer->unit_cost === null ? null : (float) $layer->unit_cost,
            'allocation_allocated_cost' => $allocation->allocated_cost === null ? null : (float) $allocation->allocated_cost,
            'replacement_consumed_cost' => $replacement->consumed_cost === null ? null : (float) $replacement->consumed_cost,
        ];

        $allKnown = $before['fifo_layer_unit_cost'] !== null && $before['allocation_allocated_cost'] !== null && $before['replacement_consumed_cost'] !== null;
        $allNull = $before['fifo_layer_unit_cost'] === null && $before['allocation_allocated_cost'] === null && $before['replacement_consumed_cost'] === null;

        $sharedResult = [
            'replacement_id' => (string) $replacement->id,
            'inventory_item_sku' => $this->itemSku($replacement->inventory_item_id),
            'inventory_item_name' => $this->itemName($replacement->inventory_item_id),
            'inventory_movement_id' => (string) $movement->id,
            'fifo_layer_id' => (string) $layer->id,
            'fifo_allocation_id' => (string) $allocation->id,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'before' => $before,
        ];

        if ($allKnown) {
            $matches = abs($before['fifo_layer_unit_cost'] - $unitCost) < 0.005
                && abs($before['allocation_allocated_cost'] - $expectedCost) < 0.005
                && abs($before['replacement_consumed_cost'] - $expectedCost) < 0.005;
            if ($matches) {
                return $sharedResult + ['status' => 'ALREADY_RECONCILED', 'after' => $before];
            }

            throw new ConflictHttpException('existing known cost conflicts with the requested unit cost - refusing to overwrite a known historical cost with a different value (fail closed)');
        }

        if (! $allNull) {
            throw new ConflictHttpException('the fifo layer/allocation/replacement cost chain is only partially known - refusing a half-fixed reconciliation (fail closed)');
        }

        $after = [
            'fifo_layer_unit_cost' => $unitCost,
            'allocation_allocated_cost' => $expectedCost,
            'replacement_consumed_cost' => $expectedCost,
        ];

        if (! $apply) {
            return $sharedResult + ['status' => 'DRY_RUN', 'after' => $after];
        }

        DB::transaction(function () use ($layer, $allocation, $replacement, $unitCost, $expectedCost) {
            $layer->update(['unit_cost' => $unitCost]);
            $allocation->update(['allocated_cost' => $expectedCost]);
            $replacement->update(['consumed_cost' => $expectedCost]);
        });

        return $sharedResult + ['status' => 'APPLIED', 'after' => $after];
    }

    private function itemSku(?string $itemId): string
    {
        return (string) (DB::table('inventory_items')->where('id', $itemId)->value('sku') ?? '');
    }

    private function itemName(?string $itemId): string
    {
        return (string) (DB::table('inventory_items')->where('id', $itemId)->value('name') ?? '');
    }
}
