<?php

namespace App\Console\Commands;

use App\Services\InventoryComponentCompatibilityBackfillService;
use Illuminate\Console\Command;
use RuntimeException;

final class BackfillInventoryComponentCompatibilities extends Command
{
    protected $signature = 'inventory:backfill-component-compatibilities
        {--dry-run : Execute the plan in a transaction, print it, then roll it back}
        {--apply : Commit the plan}';

    protected $description = 'Phase 2 transitional backfill: explicit inventory_component_compatibilities rows for existing component_id-linked items, plus the Charging Corona CMYK mapping resolved by name';

    public function handle(InventoryComponentCompatibilityBackfillService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');
        if ($dryRun === $apply) {
            throw new RuntimeException('Fail closed: specify exactly one of --dry-run or --apply');
        }

        $plan = $service->run($apply);

        $this->line('GENERIC_BACKFILL_PLANNED='.count($plan['generic_backfill']));
        foreach ($plan['generic_backfill'] as $row) {
            $this->line(json_encode(['inventory_item_id' => $row['inventory_item_id'], 'component_id' => $row['component_id']], JSON_THROW_ON_ERROR));
        }
        $this->line('CHARGING_CORONA_CREATED='.count($plan['charging_corona']['created']));
        foreach ($plan['charging_corona']['created'] as $row) {
            $this->line(json_encode(['account_id' => $row['account_id'], 'inventory_item_id' => $row['inventory_item_id'], 'component_id' => $row['component_id']], JSON_THROW_ON_ERROR));
        }
        $this->line('CHARGING_CORONA_SKIPPED='.count($plan['charging_corona']['skipped']));
        foreach ($plan['charging_corona']['skipped'] as $row) {
            $this->line(json_encode($row, JSON_THROW_ON_ERROR));
        }
        $this->line('BACKFILL_'.($dryRun ? 'DRY_RUN' : 'APPLY').'=PASS');

        return self::SUCCESS;
    }
}
