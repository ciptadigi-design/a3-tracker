<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Branch;
use App\Models\ComponentCatalog;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Machine;
use App\Models\MachineComponent;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\ModelProfile;
use App\Models\ModelProfileSlot;
use App\Models\OperationalPerson;
use App\Models\OperationalPersonBranch;
use App\Models\User;
use App\Services\InventoryLedgerService;
use App\Services\PurchaseReceiptService;
use App\Services\ReplaceMachineComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * M2.17.5.1: a user review of the M2.17.5 fix raised two follow-ups.
 *
 * 1. Historical (pre-M2.17.5) receipt movements still show no PIC. Confirmed via
 *    read-only Production forensics that operational_person_id is genuinely NULL on
 *    all four original receipt movements - this is truthful historical evidence, not
 *    a bug, and must never be backfilled from recollection (see the read-only audit
 *    in the mission report; no test exercises backfill because none is authorized).
 *
 * 2. Purchase Detail showing "Remaining: 0" after a later Component Replacement
 *    consumed stock reads as ambiguous - operators conflated procurement fulfillment
 *    with physical stock. This suite proves the two concepts are and remain
 *    independent: Component Replacement consumption must never mutate Purchase
 *    fulfillment state, matching the real Production Toner Magenta/Yellow evidence
 *    (Ordered 2 / Received 2 / Remaining to receive 0, permanently, regardless of
 *    later consumption; Inventory On Hand tracks the immutable ledger separately).
 */
class M2_17_5_1_ReceivingPicAndPurchaseSemanticsTest extends TestCase
{
    use RefreshDatabase;

    private function f(): array
    {
        $a = Account::create(['code' => 'INV5B', 'name' => 'Inventory M2175.1']);
        $b = Branch::create(['account_id' => $a->id, 'code' => 'MAIN', 'name' => 'Main']);
        $otherBranch = Branch::create(['account_id' => $a->id, 'code' => 'OTHER', 'name' => 'Other Branch']);
        $loc = InventoryLocation::create(['account_id' => $a->id, 'branch_id' => $b->id, 'code' => 'WH', 'name' => 'Warehouse']);
        $c = ComponentCatalog::create(['code' => 'TONER_M', 'name' => 'Toner Magenta']);
        $item = InventoryItem::create(['account_id' => $a->id, 'component_id' => $c->id, 'sku' => 'TONER-M-01', 'name' => 'Toner Magenta']);
        $man = Manufacturer::create(['code' => 'KM', 'name' => 'KM']);
        $model = MachineModel::create(['manufacturer_id' => $man->id, 'model_code' => 'C1070', 'name' => 'C1070']);
        $profile = ModelProfile::create(['machine_model_id' => $model->id, 'name' => 'P']);
        $slot = ModelProfileSlot::create(['profile_id' => $profile->id, 'component_id' => $c->id, 'slot_code' => 'TONER_M', 'baseline_expected_clicks' => 14000]);
        $m = Machine::create(['account_id' => $a->id, 'branch_id' => $b->id, 'machine_model_id' => $model->id, 'machine_code' => 'M1', 'display_name' => 'M1']);
        $mc = MachineComponent::create(['account_id' => $a->id, 'machine_id' => $m->id, 'component_id' => $c->id, 'profile_slot_id' => $slot->id, 'slot_code' => 'TONER_M', 'source_type' => 'inherited', 'status' => 'configured', 'active_key' => 'active', 'baseline_expected_clicks' => 14000]);
        $person = OperationalPerson::create(['account_id' => $a->id, 'name' => 'Muhammad Angga Nugraha', 'is_active' => true]);
        OperationalPersonBranch::create(['account_id' => $a->id, 'person_id' => $person->id, 'branch_id' => $b->id, 'is_active' => true, 'can_record_counter' => true]);
        $admin = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $a->id, 'user_id' => $admin->id, 'role' => 'admin', 'status' => 'active', 'accepted_at' => now()]);
        AccountMembershipBranch::create(['account_id' => $a->id, 'membership_id' => $membership->id, 'branch_id' => $b->id, 'is_active' => true]);
        AccountMembershipBranch::create(['account_id' => $a->id, 'membership_id' => $membership->id, 'branch_id' => $otherBranch->id, 'is_active' => true]);

        return compact('a', 'b', 'otherBranch', 'loc', 'item', 'mc', 'm', 'person', 'admin');
    }

    private function purchaseWithLine(array $f, float $quantity = 2): array
    {
        $p = app(PurchaseReceiptService::class)->purchase($f['a']->id, ['branch_id' => $f['b']->id, 'purchase_number' => 'PUR-1', 'purchase_date' => '2026-09-04', 'client_request_id' => (string) Str::uuid(), 'lines' => [['inventory_item_id' => $f['item']->id, 'quantity' => $quantity, 'unit_cost' => 1625000]]]);
        $line = DB::table('purchase_lines')->where('purchase_id', $p->id)->first();

        return [$p, $line];
    }

    public function test_a_realistic_receive_goods_flow_persists_and_projects_the_canonical_pic_end_to_end(): void
    {
        $f = $this->f();
        [$p, $line] = $this->purchaseWithLine($f);

        $this->actingAs($f['admin'])->postJson("/api/v1/purchases/{$p->id}/receive", [
            'location_id' => $f['loc']->id, 'person_id' => $f['person']->id, 'client_request_id' => (string) Str::uuid(),
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => 2]],
        ])->assertCreated();

        $movement = InventoryMovement::where('movement_type', 'receipt')->firstOrFail();
        $this->assertSame((string) $f['person']->id, (string) $movement->operational_person_id);
        $this->assertSame('Muhammad Angga Nugraha', $movement->operational_person_name_snapshot);
        $this->assertSame((string) $f['admin']->id, (string) $movement->entered_by);

        $workspace = $this->actingAs($f['admin'])->getJson("/api/v1/accounts/{$f['a']->id}/branches/{$f['b']->id}/inventory")->assertOk();
        $receiptLine = collect($workspace->json('data.receipts'))->firstWhere('inventory_item_id', $f['item']->id);
        $this->assertSame('Muhammad Angga Nugraha', $receiptLine['operational_person_name_snapshot']);
        $replacementMovement = collect($workspace->json('data.movements'))->firstWhere('movement_type', 'receipt');
        $this->assertSame('Muhammad Angga Nugraha', $replacementMovement['operational_person_name_snapshot']);
        // Auth actor and physical PIC are distinct - entered_by is never the display name.
        $this->assertNotEquals($f['admin']->name, $receiptLine['operational_person_name_snapshot']);
    }

    public function test_receive_goods_rejects_a_pic_from_a_different_branch(): void
    {
        $f = $this->f();
        [$p, $line] = $this->purchaseWithLine($f);
        $wrongBranchPerson = OperationalPerson::create(['account_id' => $f['a']->id, 'name' => 'Wrong Branch Person', 'is_active' => true]);
        OperationalPersonBranch::create(['account_id' => $f['a']->id, 'person_id' => $wrongBranchPerson->id, 'branch_id' => $f['otherBranch']->id, 'is_active' => true, 'can_record_counter' => true]);

        $this->actingAs($f['admin'])->postJson("/api/v1/purchases/{$p->id}/receive", [
            'location_id' => $f['loc']->id, 'person_id' => $wrongBranchPerson->id, 'client_request_id' => (string) Str::uuid(),
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => 2]],
        ])->assertStatus(422)->assertJsonValidationErrors('person_id');
    }

    public function test_receive_goods_rejects_a_pic_from_a_different_account(): void
    {
        $f = $this->f();
        [$p, $line] = $this->purchaseWithLine($f);
        $otherAccount = Account::create(['code' => 'OTH', 'name' => 'Other']);
        $otherBranch = Branch::create(['account_id' => $otherAccount->id, 'code' => 'B', 'name' => 'B']);
        $foreignPerson = OperationalPerson::create(['account_id' => $otherAccount->id, 'name' => 'Foreign Person', 'is_active' => true]);
        OperationalPersonBranch::create(['account_id' => $otherAccount->id, 'person_id' => $foreignPerson->id, 'branch_id' => $otherBranch->id, 'is_active' => true, 'can_record_counter' => true]);

        $this->actingAs($f['admin'])->postJson("/api/v1/purchases/{$p->id}/receive", [
            'location_id' => $f['loc']->id, 'person_id' => $foreignPerson->id, 'client_request_id' => (string) Str::uuid(),
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => 2]],
        ])->assertStatus(422)->assertJsonValidationErrors('person_id');
    }

    public function test_receive_goods_rejects_an_inactive_pic(): void
    {
        $f = $this->f();
        [$p, $line] = $this->purchaseWithLine($f);
        $f['person']->update(['is_active' => false]);

        $this->actingAs($f['admin'])->postJson("/api/v1/purchases/{$p->id}/receive", [
            'location_id' => $f['loc']->id, 'person_id' => $f['person']->id, 'client_request_id' => (string) Str::uuid(),
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => 2]],
        ])->assertStatus(422)->assertJsonValidationErrors('person_id');
    }

    // Phase 10's central regression: Component Replacement consumption must never
    // mutate Purchase fulfillment state - reproducing the exact real Production shape
    // (Ordered 2 / Received 2 / Remaining to receive 0, then a -1 replacement).
    public function test_component_replacement_consumption_never_mutates_purchase_remaining_to_receive(): void
    {
        $f = $this->f();
        [$p, $line] = $this->purchaseWithLine($f, 2);
        app(PurchaseReceiptService::class)->receive($p->id, $f['loc'], [['purchase_line_id' => $line->id, 'quantity' => 2]], (string) Str::uuid(), $f['person']->id, $f['person']->name, $f['admin']->id);

        $before = DB::table('purchase_lines')->where('id', $line->id)->first();
        $receivedBefore = (float) DB::table('receipt_lines')->where('purchase_line_id', $line->id)->sum('quantity');
        $this->assertSame(2.0, (float) $before->ordered_quantity);
        $this->assertSame(2.0, $receivedBefore);
        $this->assertSame(0.0, (float) $before->ordered_quantity - $receivedBefore);

        app(ReplaceMachineComponent::class)->execute($f['mc'], [
            'inventory_source' => 'inventory', 'inventory_item_id' => $f['item']->id, 'inventory_location_id' => $f['loc']->id,
            'quantity' => 1, 'performed_by_person_id' => $f['person']->id, 'performed_by_name' => $f['person']->name,
            'client_request_id' => (string) Str::uuid(),
        ]);

        // Purchase fulfillment is completely untouched by the later consumption.
        $after = DB::table('purchase_lines')->where('id', $line->id)->first();
        $receivedAfter = (float) DB::table('receipt_lines')->where('purchase_line_id', $line->id)->sum('quantity');
        $this->assertSame(2.0, (float) $after->ordered_quantity);
        $this->assertSame(2.0, $receivedAfter, 'receipt evidence must not change');
        $this->assertSame(0.0, (float) $after->ordered_quantity - $receivedAfter, 'remaining to receive must stay 0 forever');

        // Inventory On Hand is the SEPARATE fact that DOES change.
        $this->assertSame(1.0, app(InventoryLedgerService::class)->balance($f['item']->id, $f['loc']->id));
    }

    public function test_partial_receipt_remaining_to_receive_is_also_unaffected_by_later_consumption(): void
    {
        $f = $this->f();
        [$p, $line] = $this->purchaseWithLine($f, 2);
        app(PurchaseReceiptService::class)->receive($p->id, $f['loc'], [['purchase_line_id' => $line->id, 'quantity' => 1]], (string) Str::uuid(), $f['person']->id, $f['person']->name, $f['admin']->id);

        $received = (float) DB::table('receipt_lines')->where('purchase_line_id', $line->id)->sum('quantity');
        $this->assertSame(1.0, $received);
        $this->assertSame(1.0, (float) $line->ordered_quantity - $received, 'partial receipt: 1 of 2 remains to receive');

        app(ReplaceMachineComponent::class)->execute($f['mc'], [
            'inventory_source' => 'inventory', 'inventory_item_id' => $f['item']->id, 'inventory_location_id' => $f['loc']->id,
            'quantity' => 1, 'client_request_id' => (string) Str::uuid(),
        ]);

        $receivedAfter = (float) DB::table('receipt_lines')->where('purchase_line_id', $line->id)->sum('quantity');
        $this->assertSame(1.0, $receivedAfter);
        $this->assertSame(1.0, (float) $line->ordered_quantity - $receivedAfter, 'consumption must not change the partial remaining-to-receive figure');
        $this->assertSame(0.0, app(InventoryLedgerService::class)->balance($f['item']->id, $f['loc']->id));
    }
}
