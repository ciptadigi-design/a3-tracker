<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\ComponentCatalog;
use App\Models\ComponentLifecycle;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\Machine;
use App\Models\MachineComponent;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\ModelProfile;
use App\Models\ModelProfileSlot;
use App\Services\InventoryLedgerService;
use App\Services\PurchaseReceiptService;
use App\Services\ReplaceMachineComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class InventoryFifoReplacementParityTest extends TestCase
{
    use RefreshDatabase;

    private function f(): array
    {
        $a = Account::create(['code' => 'INV', 'name' => 'Inventory']);
        $b = Branch::create(['account_id' => $a->id, 'code' => 'MAIN', 'name' => 'Main']);
        $loc = InventoryLocation::create(['account_id' => $a->id, 'branch_id' => $b->id, 'code' => 'WH', 'name' => 'Warehouse']);
        $c = ComponentCatalog::create(['code' => 'DRUM', 'name' => 'Drum']);
        $item = InventoryItem::create(['account_id' => $a->id, 'component_id' => $c->id, 'sku' => 'DRUM-01', 'name' => 'Drum']);
        $man = Manufacturer::create(['code' => 'KM', 'name' => 'KM']);
        $model = MachineModel::create(['manufacturer_id' => $man->id, 'model_code' => 'C', 'name' => 'C']);
        $profile = ModelProfile::create(['machine_model_id' => $model->id, 'name' => 'P']);
        $slot = ModelProfileSlot::create(['profile_id' => $profile->id, 'component_id' => $c->id, 'slot_code' => 'DRUM-C']);
        $m = Machine::create(['account_id' => $a->id, 'branch_id' => $b->id, 'machine_model_id' => $model->id, 'machine_code' => 'M', 'display_name' => 'M']);
        $mc = MachineComponent::create(['account_id' => $a->id, 'machine_id' => $m->id, 'component_id' => $c->id, 'profile_slot_id' => $slot->id, 'slot_code' => 'DRUM-C', 'source_type' => 'inherited', 'status' => 'configured', 'active_key' => 'active']);

        return compact('a', 'loc', 'item', 'mc');
    }

    public function test_purchase_has_no_stock_effect_and_partial_receipts_create_fifo(): void
    {
        $f = $this->f();
        $p = app(PurchaseReceiptService::class)->purchase($f['a']->id, ['purchase_number' => 'P1', 'purchase_date' => '2026-08-31', 'client_request_id' => (string) Str::uuid(), 'lines' => [['inventory_item_id' => $f['item']->id, 'quantity' => 10, 'unit_cost' => 100]]]);
        $this->assertSame(0.0, app(InventoryLedgerService::class)->balance($f['item']->id, $f['loc']->id));
        $line = DB::table('purchase_lines')->where('purchase_id', $p->id)->first();
        app(PurchaseReceiptService::class)->receive($p->id, $f['loc'], [['purchase_line_id' => $line->id, 'quantity' => 4]], (string) Str::uuid());
        $this->assertSame(4.0, app(InventoryLedgerService::class)->balance($f['item']->id, $f['loc']->id));
    }

    public function test_fifo_consumes_oldest_layers_and_transfer_conserves_stock(): void
    {
        $f = $this->f();
        $l = app(InventoryLedgerService::class);
        $l->inbound($f['item'], $f['loc'], 2, 100, 'opening_balance', (string) Str::uuid());
        $l->inbound($f['item'], $f['loc'], 3, 200, 'receipt', (string) Str::uuid());
        $out = $l->outbound($f['item'], $f['loc'], 4, 'adjustment_out', (string) Str::uuid(), null, 'use');
        $this->assertSame(600.0, (float) DB::table('fifo_allocations')->where('outbound_movement_id', $out->id)->sum('allocated_cost'));
        $to = InventoryLocation::create(['account_id' => $f['a']->id, 'code' => 'B', 'name' => 'B']);
        $l->transfer($f['item'], $f['loc'], $to, 1, (string) Str::uuid());
        $this->assertSame(0.0, app(InventoryLedgerService::class)->balance($f['item']->id, $f['loc']->id));
        $this->assertSame(1.0, app(InventoryLedgerService::class)->balance($f['item']->id, $to->id));
    }

    public function test_external_replacement_transitions_lifecycle_without_stock_and_tracked_mismatch_rejects(): void
    {
        $f = $this->f();
        $r = app(ReplaceMachineComponent::class)->execute($f['mc'], ['inventory_source' => 'external_untracked', 'external_reason' => 'customer supplied', 'replaced_at' => '2026-08-31 10:00:00', 'client_request_id' => (string) Str::uuid()]);
        $this->assertNull($r->inventory_movement_id);
        $this->assertSame(1, ComponentLifecycle::where('machine_component_id', $f['mc']->id)->count());
        $bad = InventoryItem::create(['account_id' => $f['a']->id, 'component_id' => ComponentCatalog::create(['code' => 'X', 'name' => 'X'])->id, 'sku' => 'X', 'name' => 'X']);
        $this->expectException(ConflictHttpException::class);
        app(ReplaceMachineComponent::class)->execute($f['mc'], ['inventory_source' => 'inventory', 'inventory_item_id' => $bad->id, 'inventory_location_id' => $f['loc']->id, 'quantity' => 1, 'client_request_id' => (string) Str::uuid()]);
    }
}
