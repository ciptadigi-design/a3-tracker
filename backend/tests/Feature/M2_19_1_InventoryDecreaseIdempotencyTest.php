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

/**
 * M2.19 (closing the M2.18 audit's inventory-decrease idempotency finding):
 * InventoryLedgerService::outbound() had no idempotency check at all, unlike
 * inbound() (which checks for an existing InventoryMovement by
 * account_id+client_request_id+movement_type before creating one).
 * transfer() and ReplaceMachineComponent both protect themselves with an
 * upstream check before ever calling outbound(), but
 * InventoryController::adjust()'s decrease branch calls outbound() directly
 * with no upstream check - a double-submitted "decrease stock" request
 * (network retry, double-click) could consume inventory twice.
 */
class M2_19_1_InventoryDecreaseIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(float $opening = 10): array
    {
        $account = Account::create(['code' => 'IDEM', 'name' => 'Idempotency']);
        $branch = Branch::create(['account_id' => $account->id, 'code' => 'MAIN', 'name' => 'Main']);
        $location = InventoryLocation::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'code' => 'WH', 'name' => 'Warehouse']);
        $item = InventoryItem::create(['account_id' => $account->id, 'sku' => 'IDEM-01', 'name' => 'Idempotent Item']);
        $ledger = app(InventoryLedgerService::class);
        $ledger->inbound($item, $location, $opening, 100, 'opening_balance', (string) Str::uuid());

        return compact('account', 'branch', 'location', 'item', 'ledger');
    }

    public function test_the_exact_same_client_request_id_submitted_twice_decrements_stock_only_once(): void
    {
        $f = $this->fixture(10);
        $requestId = (string) Str::uuid();

        $first = $f['ledger']->outbound($f['item'], $f['location'], 3, 'adjustment_out', $requestId, null, 'test decrease');
        $second = $f['ledger']->outbound($f['item'], $f['location'], 3, 'adjustment_out', $requestId, null, 'test decrease');

        $this->assertSame((string) $first->id, (string) $second->id, 'the exact same request must return the same movement, not create a second one');
        $this->assertSame(7.0, $f['ledger']->balance($f['item']->id, $f['location']->id), 'stock must only be decremented once for one logical operation');
        $this->assertSame(1, DB::table('inventory_movements')->where('client_request_id', $requestId)->count(), 'exactly one movement for this client_request_id');
        $this->assertSame(1, DB::table('fifo_allocations')->where('outbound_movement_id', $first->id)->count(), 'exactly one FIFO consumption effect');
    }

    public function test_a_different_client_request_id_is_a_genuinely_separate_adjustment(): void
    {
        $f = $this->fixture(10);

        $first = $f['ledger']->outbound($f['item'], $f['location'], 3, 'adjustment_out', (string) Str::uuid(), null, 'first decrease');
        $second = $f['ledger']->outbound($f['item'], $f['location'], 2, 'adjustment_out', (string) Str::uuid(), null, 'second decrease');

        $this->assertNotSame((string) $first->id, (string) $second->id);
        $this->assertSame(5.0, $f['ledger']->balance($f['item']->id, $f['location']->id), 'two genuinely separate decreases must both apply');
        $this->assertSame(2, DB::table('inventory_movements')->where('movement_type', 'adjustment_out')->count());
    }

    public function test_retrying_after_stock_has_since_dropped_below_the_original_quantity_still_returns_the_historical_movement(): void
    {
        // A retry must report the fact that already happened, not re-validate
        // against whatever the CURRENT balance happens to be - otherwise a
        // legitimate retry of an already-completed decrease could incorrectly
        // throw "insufficient stock" once other real activity has consumed
        // the remaining balance.
        $f = $this->fixture(5);
        $requestId = (string) Str::uuid();

        $first = $f['ledger']->outbound($f['item'], $f['location'], 4, 'adjustment_out', $requestId, null, 'decrease');
        $this->assertSame(1.0, $f['ledger']->balance($f['item']->id, $f['location']->id));

        // Balance is now 1; a second real, unrelated decrease should still
        // work but is not part of this scenario. Retry the ORIGINAL request.
        $second = $f['ledger']->outbound($f['item'], $f['location'], 4, 'adjustment_out', $requestId, null, 'decrease');
        $this->assertSame((string) $first->id, (string) $second->id);
        $this->assertSame(1.0, $f['ledger']->balance($f['item']->id, $f['location']->id), 'the retry must not attempt to decrement stock again');
    }

    public function test_two_concurrent_processes_retrying_the_same_client_request_id_produce_exactly_one_movement(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('The row-lock race is an explicit MySQL/InnoDB acceptance gate, matching InventoryMysqlConcurrencyTest.');
        }
        $f = $this->fixture(10);
        $requestId = (string) Str::uuid();
        DB::connection()->commit();

        $processes = [];
        foreach ([1, 2] as $n) {
            $processes[$n] = new Process([PHP_BINARY, base_path('tests/Support/consume_inventory_same_request.php'), (string) $f['item']->id, (string) $f['location']->id, $requestId], base_path());
            $processes[$n]->start();
        }
        foreach ($processes as $process) {
            $process->wait();
        }
        $results = array_map(fn (Process $p) => json_decode($p->getOutput(), true), $processes);
        $diagnostic = array_map(fn (Process $p, $result) => ['exit_code' => $p->getExitCode(), 'result' => $result, 'stderr' => trim($p->getErrorOutput())], $processes, $results);
        foreach ($results as $r) {
            $this->assertTrue($r['ok'] ?? false, json_encode($diagnostic, JSON_PRETTY_PRINT));
        }
        $movementIds = array_unique(array_map(fn ($r) => $r['movement_id'], $results));
        $this->assertCount(1, $movementIds, 'both concurrent retries of the same request must resolve to the same single movement: '.json_encode($diagnostic, JSON_PRETTY_PRINT));
        $this->assertSame(7.0, app(InventoryLedgerService::class)->balance($f['item']->id, $f['location']->id), 'stock must be decremented exactly once, not twice');
        $this->assertSame(1, DB::table('inventory_movements')->where('client_request_id', $requestId)->count());

        DB::connection()->beginTransaction();
    }
}
