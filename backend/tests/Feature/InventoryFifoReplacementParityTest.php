<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Branch;
use App\Models\ComponentCatalog;
use App\Models\ComponentLifecycle;
use App\Models\FifoAllocation;
use App\Models\FifoLayer;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Machine;
use App\Models\MachineComponent;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\ModelProfile;
use App\Models\ModelProfileSlot;
use App\Models\User;
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
        $generic = InventoryItem::create(['account_id' => $a->id, 'component_id' => null, 'sku' => 'GEN-01', 'name' => 'Generic']);
        $man = Manufacturer::create(['code' => 'KM', 'name' => 'KM']);
        $model = MachineModel::create(['manufacturer_id' => $man->id, 'model_code' => 'C', 'name' => 'C']);
        $profile = ModelProfile::create(['machine_model_id' => $model->id, 'name' => 'P']);
        $slot = ModelProfileSlot::create(['profile_id' => $profile->id, 'component_id' => $c->id, 'slot_code' => 'DRUM-C']);
        $m = Machine::create(['account_id' => $a->id, 'branch_id' => $b->id, 'machine_model_id' => $model->id, 'machine_code' => 'M', 'display_name' => 'M']);
        $mc = MachineComponent::create(['account_id' => $a->id, 'machine_id' => $m->id, 'component_id' => $c->id, 'profile_slot_id' => $slot->id, 'slot_code' => 'DRUM-C', 'source_type' => 'inherited', 'status' => 'configured', 'active_key' => 'active']);

        return compact('a', 'b', 'loc', 'item', 'generic', 'mc', 'm');
    }

    public function test_purchase_is_acquisition_only_and_receiving_is_partial_idempotent_and_capped(): void
    {
        $f = $this->f();
        $p = app(PurchaseReceiptService::class)->purchase($f['a']->id, ['purchase_number' => 'P1', 'purchase_date' => '2026-08-31', 'client_request_id' => (string) Str::uuid(), 'lines' => [['inventory_item_id' => $f['item']->id, 'quantity' => 10, 'unit_cost' => 100]]]);
        $this->assertSame(0.0, app(InventoryLedgerService::class)->balance($f['item']->id, $f['loc']->id));
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, FifoLayer::count());
        $line = DB::table('purchase_lines')->where('purchase_id', $p->id)->first();
        $request = (string) Str::uuid();
        $first = app(PurchaseReceiptService::class)->receive($p->id, $f['loc'], [['purchase_line_id' => $line->id, 'quantity' => 4]], $request);
        $this->assertSame(4.0, app(InventoryLedgerService::class)->balance($f['item']->id, $f['loc']->id));
        $this->assertSame(1, InventoryMovement::where('movement_type', 'receipt')->count());
        $this->assertSame(1, FifoLayer::count());
        $this->assertSame($first->id, app(PurchaseReceiptService::class)->receive($p->id, $f['loc'], [['purchase_line_id' => $line->id, 'quantity' => 4]], $request)->id);
        app(PurchaseReceiptService::class)->receive($p->id, $f['loc'], [['purchase_line_id' => $line->id, 'quantity' => 6]], (string) Str::uuid());
        $this->assertSame(10.0, app(InventoryLedgerService::class)->balance($f['item']->id, $f['loc']->id));
        $this->assertSame('received', DB::table('purchases')->where('id', $p->id)->value('status'));
        try {
            app(PurchaseReceiptService::class)->receive($p->id, $f['loc'], [['purchase_line_id' => $line->id, 'quantity' => 1]], (string) Str::uuid());
            $this->fail('over-receipt should fail');
        } catch (ConflictHttpException) {
            $this->assertSame(10.0, app(InventoryLedgerService::class)->balance($f['item']->id, $f['loc']->id));
        }
    }

    public function test_opening_unknown_cost_and_generic_item_are_preserved(): void
    {
        $f = $this->f();
        $m = app(InventoryLedgerService::class)->inbound($f['generic'], $f['loc'], 3, null, 'opening_balance', (string) Str::uuid(), 'physical count', '2026-08-31 08:00:00');
        $this->assertSame(3.0, app(InventoryLedgerService::class)->balance($f['generic']->id, $f['loc']->id));
        $this->assertNull(FifoLayer::where('inbound_movement_id', $m->id)->value('unit_cost'));
        $out = app(InventoryLedgerService::class)->outbound($f['generic'], $f['loc'], 1, 'adjustment_out', (string) Str::uuid(), null, 'use');
        $this->assertNull(FifoAllocation::where('outbound_movement_id', $out->id)->value('allocated_cost'));
    }

    public function test_fifo_is_deterministic_isolated_and_reconciles_to_ledger(): void
    {
        $f = $this->f();
        $l = app(InventoryLedgerService::class);
        $l->inbound($f['item'], $f['loc'], 2, 100, 'opening_balance', (string) Str::uuid(), null, '2026-08-31 08:00:00');
        $l->inbound($f['item'], $f['loc'], 3, 200, 'receipt', (string) Str::uuid(), null, '2026-08-31 08:01:00');
        $out = $l->outbound($f['item'], $f['loc'], 4, 'adjustment_out', (string) Str::uuid(), null, 'use');
        $this->assertSame(600.0, (float) FifoAllocation::where('outbound_movement_id', $out->id)->sum('allocated_cost'));
        $this->assertSame([2.0, 2.0], FifoAllocation::where('outbound_movement_id', $out->id)->orderBy('allocation_order')->pluck('quantity')->map(fn ($v) => (float) $v)->all());
        $this->assertSame(1.0, (float) FifoLayer::where('inventory_item_id', $f['item']->id)->sum('remaining_quantity'));
        $this->assertSame(1.0, $l->balance($f['item']->id, $f['loc']->id));
        $other = InventoryItem::create(['account_id' => $f['a']->id, 'sku' => 'OTHER', 'name' => 'Other']);
        $l->inbound($other, $f['loc'], 2, 999, 'opening_balance', (string) Str::uuid());
        $this->assertSame(2.0, $l->balance($other->id, $f['loc']->id));
    }

    public function test_transfer_is_atomic_conserves_total_preserves_cost_and_is_idempotent(): void
    {
        $f = $this->f();
        $to = InventoryLocation::create(['account_id' => $f['a']->id, 'code' => 'B', 'name' => 'B']);
        $l = app(InventoryLedgerService::class);
        $l->inbound($f['item'], $f['loc'], 3, 150, 'opening_balance', (string) Str::uuid());
        $request = (string) Str::uuid();
        [, $in] = $l->transfer($f['item'], $f['loc'], $to, 2, $request);
        [, $retryIn] = $l->transfer($f['item'], $f['loc'], $to, 2, $request);
        $this->assertSame((string) $in->id, (string) $retryIn->id);
        $this->assertSame(1.0, $l->balance($f['item']->id, $f['loc']->id));
        $this->assertSame(2.0, $l->balance($f['item']->id, $to->id));
        $this->assertSame(3.0, $l->balance($f['item']->id, $f['loc']->id) + $l->balance($f['item']->id, $to->id));
        $this->assertSame(150.0, (float) FifoLayer::where('location_id', $to->id)->value('unit_cost'));
        $this->expectException(ConflictHttpException::class);
        $l->transfer($f['item'], $f['loc'], $f['loc'], 1, (string) Str::uuid());
    }

    public function test_adjustments_require_reason_and_negative_adjustment_consumes_fifo(): void
    {
        $f = $this->f();
        $u = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $f['a']->id, 'user_id' => $u->id, 'role' => 'owner', 'status' => 'active']);
        AccountMembershipBranch::create(['account_id' => $f['a']->id, 'membership_id' => $membership->id, 'branch_id' => $f['b']->id, 'is_active' => true]);
        app(InventoryLedgerService::class)->inbound($f['item'], $f['loc'], 3, 100, 'opening_balance', (string) Str::uuid());
        $this->actingAs($u)->postJson('/api/v1/inventory/adjustments', ['item_id' => $f['item']->id, 'location_id' => $f['loc']->id, 'quantity' => 2, 'client_request_id' => (string) Str::uuid()])->assertStatus(422);
        $this->actingAs($u)->postJson('/api/v1/inventory/adjustments', ['item_id' => $f['item']->id, 'location_id' => $f['loc']->id, 'quantity' => 2, 'reason' => 'count', 'client_request_id' => (string) Str::uuid()])->assertCreated();
        $this->actingAs($u)->postJson('/api/v1/inventory/adjustments', ['item_id' => $f['item']->id, 'location_id' => $f['loc']->id, 'quantity' => -1, 'reason' => 'issue', 'client_request_id' => (string) Str::uuid()])->assertCreated();
        $this->assertSame(4.0, app(InventoryLedgerService::class)->balance($f['item']->id, $f['loc']->id));
    }

    public function test_tracked_replacement_consumes_exact_scope_costs_and_transitions_lifecycle(): void
    {
        $f = $this->f();
        $l = app(InventoryLedgerService::class);
        $l->inbound($f['item'], $f['loc'], 1, 100, 'opening_balance', (string) Str::uuid());
        $l->inbound($f['item'], $f['loc'], 1, 200, 'receipt', (string) Str::uuid());
        $previous = ComponentLifecycle::create(['machine_component_id' => $f['mc']->id, 'started_at' => '2026-08-01 00:00:00', 'status' => 'active', 'active_key' => 'active']);
        $request = (string) Str::uuid();
        $r = app(ReplaceMachineComponent::class)->execute($f['mc'], ['inventory_source' => 'inventory', 'inventory_item_id' => $f['item']->id, 'inventory_location_id' => $f['loc']->id, 'quantity' => 2, 'replaced_at' => '2026-08-31 10:00:00', 'client_request_id' => $request]);
        $this->assertEquals($f['item']->id, $r->inventory_item_id);
        $this->assertEquals($f['loc']->id, $r->inventory_location_id);
        $this->assertSame(2.0, (float) FifoAllocation::where('outbound_movement_id', $r->inventory_movement_id)->sum('quantity'));
        $this->assertSame(300.0, (float) $r->consumed_cost);
        $this->assertSame(-2.0, (float) InventoryMovement::find($r->inventory_movement_id)->quantity);
        $this->assertSame('closed', $previous->fresh()->status);
        $this->assertSame('2026-08-31 10:00:00', $r->newLifecycle->started_at->format('Y-m-d H:i:s'));
        $this->assertSame((string) $r->id, (string) app(ReplaceMachineComponent::class)->execute($f['mc'], ['inventory_source' => 'inventory', 'inventory_item_id' => $f['item']->id, 'inventory_location_id' => $f['loc']->id, 'quantity' => 2, 'client_request_id' => $request])->id);
        $this->assertSame(1, DB::table('component_replacements')->count());
    }

    public function test_external_replacement_and_unknown_predecessor_do_not_fabricate_stock_or_cost(): void
    {
        $f = $this->f();
        $r = app(ReplaceMachineComponent::class)->execute($f['mc'], ['inventory_source' => 'external_untracked', 'external_reason' => 'customer supplied', 'replaced_at' => '2026-08-31 10:00:00', 'client_request_id' => (string) Str::uuid()]);
        $this->assertNull($r->inventory_movement_id);
        $this->assertNull($r->consumed_cost);
        $this->assertNull($r->previous_lifecycle_id);
        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(0, FifoAllocation::count());
        $this->assertSame(1, ComponentLifecycle::where('machine_component_id', $f['mc']->id)->count());
    }

    public function test_mismatched_item_and_retired_machine_are_rejected_atomically(): void
    {
        $f = $this->f();
        $other = ComponentCatalog::create(['code' => 'OTHER', 'name' => 'Other']);
        $bad = InventoryItem::create(['account_id' => $f['a']->id, 'component_id' => $other->id, 'sku' => 'OTHER-01', 'name' => 'Other']);
        try {
            app(ReplaceMachineComponent::class)->execute($f['mc'], ['inventory_source' => 'inventory', 'inventory_item_id' => $bad->id, 'inventory_location_id' => $f['loc']->id, 'quantity' => 1, 'client_request_id' => (string) Str::uuid()]);
            $this->fail('mismatch should fail');
        } catch (ConflictHttpException) {
            $this->assertSame(0, InventoryMovement::count());
        } $f['m']->update(['status' => 'retired']);
        $this->expectException(ConflictHttpException::class);
        app(ReplaceMachineComponent::class)->execute($f['mc'],['inventory_source' => 'external_untracked', 'external_reason' => 'x', 'client_request_id' => (string) Str::uuid()]);
    }

    public function test_inventory_movement_ledger_is_append_only(): void
    {
        $f = $this->f();
        $movement = app(InventoryLedgerService::class)->inbound($f['item'],$f['loc'],1,null,'opening_balance',(string) Str::uuid());
        $this->expectException(ConflictHttpException::class);
        $movement->update(['reason' => 'tamper']);
    }

    public function test_inventory_api_denies_cross_account_and_unassigned_branch_access(): void
    {
        $f = $this->f();
        $user = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $f['a']->id, 'user_id' => $user->id, 'role' => 'admin', 'status' => 'active']);
        AccountMembershipBranch::create(['account_id' => $f['a']->id, 'membership_id' => $membership->id, 'branch_id' => $f['b']->id, 'is_active' => true]);
        $otherBranch = Branch::create(['account_id' => $f['a']->id, 'code' => 'OTHER', 'name' => 'Other']);
        $otherLocation = InventoryLocation::create(['account_id' => $f['a']->id, 'branch_id' => $otherBranch->id, 'code' => 'OTHER', 'name' => 'Other']);
        $otherAccount = Account::create(['code' => 'OTHER', 'name' => 'Other']);
        $otherItem = InventoryItem::create(['account_id' => $otherAccount->id, 'sku' => 'OTHER', 'name' => 'Other']);
        $otherAccountLocation = InventoryLocation::create(['account_id' => $otherAccount->id, 'code' => 'WH', 'name' => 'Other WH']);
        $this->actingAs($user)->getJson('/api/v1/inventory/locations')->assertOk()->assertJsonMissing(['id' => (string) $otherLocation->id]);
        $this->actingAs($user)->getJson('/api/v1/inventory/items/'.$otherItem->id.'/locations/'.$otherAccountLocation->id.'/balance')->assertForbidden();
        $this->actingAs($user)->postJson('/api/v1/inventory/transfers', ['item_id' => $f['item']->id, 'from_location_id' => $f['loc']->id, 'to_location_id' => $otherLocation->id, 'quantity' => 1, 'client_request_id' => (string) Str::uuid()])->assertForbidden();
        $this->actingAs($user)->postJson('/api/v1/purchases', ['account_id' => $otherAccount->id, 'purchase_number' => 'X', 'purchase_date' => '2026-08-31', 'client_request_id' => (string) Str::uuid(), 'lines' => [['inventory_item_id' => $otherItem->id, 'quantity' => 1]]])->assertForbidden();
    }
}
