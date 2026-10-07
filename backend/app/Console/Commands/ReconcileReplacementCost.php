<?php

namespace App\Console\Commands;

use App\Services\ReplacementCostReconciliationService;
use Illuminate\Console\Command;
use RuntimeException;

final class ReconcileReplacementCost extends Command
{
    protected $signature = 'inventory:reconcile-replacement-cost
        {replacement_id : component_replacements.id to reconcile}
        {--unit-cost= : Authoritative unit cost to apply}
        {--apply : Commit the reconciliation (default is dry-run; never implicit)}';

    protected $description = 'Controlled administrative reconciliation of a historical component replacement whose inventory cost was originally recorded as unknown (single-FIFO-allocation case only)';

    public function handle(ReplacementCostReconciliationService $service): int
    {
        $unitCostOption = $this->option('unit-cost');
        if ($unitCostOption === null || ! is_numeric($unitCostOption) || (float) $unitCostOption <= 0) {
            throw new RuntimeException('--unit-cost=<positive amount> is required');
        }

        $apply = (bool) $this->option('apply');
        $result = $service->reconcile($this->argument('replacement_id'), (float) $unitCostOption, $apply);

        $this->line('REPLACEMENT_ID='.$result['replacement_id']);
        $this->line('INVENTORY_ITEM='.$result['inventory_item_sku'].' / '.$result['inventory_item_name']);
        $this->line('INVENTORY_MOVEMENT_ID='.$result['inventory_movement_id']);
        $this->line('FIFO_LAYER_ID='.$result['fifo_layer_id']);
        $this->line('FIFO_ALLOCATION_ID='.$result['fifo_allocation_id']);
        $this->line('QUANTITY='.$result['quantity']);
        $this->line('UNIT_COST='.$result['unit_cost']);
        $this->line('FIFO_LAYER_UNIT_COST_BEFORE='.($result['before']['fifo_layer_unit_cost'] ?? 'NULL'));
        $this->line('FIFO_LAYER_UNIT_COST_AFTER='.($result['after']['fifo_layer_unit_cost'] ?? 'NULL'));
        $this->line('ALLOCATION_ALLOCATED_COST_BEFORE='.($result['before']['allocation_allocated_cost'] ?? 'NULL'));
        $this->line('ALLOCATION_ALLOCATED_COST_AFTER='.($result['after']['allocation_allocated_cost'] ?? 'NULL'));
        $this->line('REPLACEMENT_CONSUMED_COST_BEFORE='.($result['before']['replacement_consumed_cost'] ?? 'NULL'));
        $this->line('REPLACEMENT_CONSUMED_COST_AFTER='.($result['after']['replacement_consumed_cost'] ?? 'NULL'));
        $this->line('STATUS='.$result['status']);
        $this->line('MODE='.($apply ? 'APPLY' : 'DRY_RUN'));

        return self::SUCCESS;
    }
}
