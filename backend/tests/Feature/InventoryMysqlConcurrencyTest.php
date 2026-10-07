<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\ComponentCatalog;
use App\Models\ComponentLifecycle;
use App\Models\ComponentReplacement;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\Machine;
use App\Models\MachineComponent;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\ModelProfile;
use App\Models\ModelProfileSlot;
use App\Services\InventoryComponentCompatibilityService;
use App\Services\InventoryLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InventoryMysqlConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_competing_consumers_cannot_double_spend_one_unit(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('The row-lock race is an explicit MySQL/InnoDB acceptance gate.');
        }
        $a = Account::create(['code' => 'RACE', 'name' => 'Race']);
        $b = Branch::create(['account_id' => $a->id, 'code' => 'MAIN', 'name' => 'Main']);
        $loc = InventoryLocation::create(['account_id' => $a->id, 'branch_id' => $b->id, 'code' => 'WH', 'name' => 'Warehouse']);
        $item = InventoryItem::create(['account_id' => $a->id, 'sku' => 'RACE-01', 'name' => 'Race item']);
        app(InventoryLedgerService::class)->inbound($item, $loc, 1, 100, 'opening_balance', (string) Str::uuid());
        DB::connection()->commit();
        $processes = [];
        foreach ([1, 2] as $n) {
            $processes[$n] = new Process([PHP_BINARY, base_path('tests/Support/consume_inventory.php'), (string) $item->id, (string) $loc->id, (string) Str::uuid()], base_path());
            $processes[$n]->start();
        }
        foreach ($processes as $process) {
            $process->wait();
        }
        $results = array_map(fn (Process $p) => json_decode($p->getOutput(), true), $processes);
        $successes = count(array_filter($results, fn ($r) => $r['ok'] ?? false));
        $diagnostic = array_map(fn (Process $p, $result) => ['exit_code' => $p->getExitCode(), 'result' => $result, 'stderr' => trim($p->getErrorOutput())], $processes, $results);
        $this->assertSame(1, $successes, json_encode($diagnostic, JSON_PRETTY_PRINT));
        $this->assertSame(0.0, app(InventoryLedgerService::class)->balance($item->id, $loc->id));
        $this->assertSame(0.0, (float) DB::table('fifo_layers')->where('inventory_item_id', $item->id)->sum('remaining_quantity'));
        $this->assertSame(1, DB::table('fifo_allocations')->count());
        DB::connection()->beginTransaction();
    }

    // Generic part compatibility Phase 2, requirement #13: concurrent
    // replacement protection remains. Deliberately placed as a second method
    // in THIS file/class (not a separate test file) so its own real,
    // permanently-committed subprocess writes land in the exact same
    // already-proven-safe position in the whole suite's run order as the
    // test above - a separate new file sorting earlier alphabetically
    // leaked its committed rows into unrelated tests' unscoped global counts
    // (observed directly in CI; fixed by merging here instead).
    public function test_two_competing_component_replacements_cannot_double_spend_one_unit(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('The row-lock race is an explicit MySQL/InnoDB acceptance gate.');
        }
        $suffix = (string) Str::uuid();
        $account = Account::create(['code' => 'RACE2-'.$suffix, 'name' => 'Race']);
        $branch = Branch::create(['account_id' => $account->id, 'code' => 'MAIN', 'name' => 'Main']);
        $loc = InventoryLocation::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'code' => 'WH', 'name' => 'Warehouse']);
        $manufacturer = Manufacturer::create(['code' => 'KM-'.$suffix, 'name' => 'KM']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'C-'.$suffix, 'name' => 'C']);
        $profile = ModelProfile::create(['machine_model_id' => $model->id, 'name' => 'P']);
        $component = ComponentCatalog::create(['account_id' => $account->id, 'code' => 'corona-k-'.$suffix, 'name' => 'Charging Corona K Def', 'is_active' => true]);
        $slot = ModelProfileSlot::create(['profile_id' => $profile->id, 'component_id' => $component->id, 'slot_code' => 'K']);
        $machine = Machine::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'M-'.$suffix, 'display_name' => 'M', 'status' => 'active']);
        $mc = MachineComponent::create(['account_id' => $account->id, 'machine_id' => $machine->id, 'component_id' => $component->id, 'profile_slot_id' => $slot->id, 'slot_code' => 'K', 'source_type' => 'inherited', 'status' => 'configured', 'active_key' => 'active']);
        ComponentLifecycle::create(['machine_component_id' => $mc->id, 'started_at' => now()->subDays(10), 'status' => 'active', 'active_key' => 'active', 'source' => 'manual']);
        $generic = InventoryItem::create(['account_id' => $account->id, 'component_id' => null, 'sku' => 'GEN-CORONA-'.$suffix, 'name' => 'Charging Corona', 'is_active' => true]);
        app(InventoryComponentCompatibilityService::class)->link($generic, $component->id);
        app(InventoryLedgerService::class)->inbound($generic, $loc, 1, 1000, 'opening_balance', (string) Str::uuid(), 'opening');
        DB::connection()->commit();

        $processes = [];
        foreach ([1, 2] as $n) {
            $processes[$n] = new Process([PHP_BINARY, base_path('tests/Support/replace_machine_component_concurrent.php'), (string) $mc->id, (string) $generic->id, (string) $loc->id, (string) Str::uuid()], base_path());
            $processes[$n]->start();
        }
        foreach ($processes as $process) {
            $process->wait();
        }
        $results = array_map(fn (Process $p) => json_decode($p->getOutput(), true), $processes);
        $diagnostic = array_map(fn (Process $p, $result) => ['exit_code' => $p->getExitCode(), 'result' => $result, 'stderr' => trim($p->getErrorOutput())], $processes, $results);

        DB::connection()->beginTransaction();
        $successes = count(array_filter($results, fn ($r) => $r['ok'] ?? false));
        $this->assertSame(1, $successes, json_encode($diagnostic, JSON_PRETTY_PRINT));
        $this->assertSame(0.0, app(InventoryLedgerService::class)->balance($generic->id, $loc->id));
        $this->assertSame(1, ComponentLifecycle::where('machine_component_id', $mc->id)->where('status', 'active')->count(), 'exactly one active lifecycle must exist, never two or zero');
        $this->assertSame(1, ComponentReplacement::where('machine_component_id', $mc->id)->count());
    }
}
