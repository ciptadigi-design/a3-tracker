<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
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
}
