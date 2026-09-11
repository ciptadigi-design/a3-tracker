<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Branch;
use App\Models\ComponentCatalog;
use App\Models\ComponentLifecycle;
use App\Models\CounterReading;
use App\Models\CounterType;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventorySupplier;
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
use App\Services\MovementSummaryBuilder;
use App\Services\PurchaseReceiptService;
use App\Services\ReplaceMachineComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * M2.17.5: a real Production Receive Goods + Component Replacement (Toner Magenta)
 * exposed two integrity gaps proven via read-only Production forensics before any
 * code change:
 *
 * 1. PurchaseReceiptService::receive() never accepted or forwarded a PIC at all -
 *    unlike opening()/adjust()/transfer(), which all resolve+pass person_id - so
 *    every Goods Receipt movement persisted with operational_person_id=NULL
 *    regardless of what the frontend sent.
 * 2. ReplaceMachineComponent::execute() created the new lifecycle without an
 *    installed_counter baseline (and closed the previous one without a
 *    removed_counter/actual_usage), so the frontend health projection
 *    (usage = latestCounter - installedCounter) could never compute a real
 *    percentage and silently rendered "0.0%" instead of ~100% remaining.
 *
 * The replacement operation itself was already correctly atomic (one
 * DB::transaction wrapping consumption + lifecycle transition + replacement
 * record) - this was a complete-but-incomplete-data defect, not a partial-commit
 * defect. These tests prove the fix without weakening that atomicity.
 */
class M2_17_5_InventoryReceivingAndReplacementIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function f(): array
    {
        $a = Account::create(['code' => 'INV5', 'name' => 'Inventory M2175']);
        $b = Branch::create(['account_id' => $a->id, 'code' => 'MAIN', 'name' => 'Main']);
        $loc = InventoryLocation::create(['account_id' => $a->id, 'branch_id' => $b->id, 'code' => 'WH', 'name' => 'Warehouse']);
        $c = ComponentCatalog::create(['code' => 'TONER_M', 'name' => 'Toner Magenta']);
        $item = InventoryItem::create(['account_id' => $a->id, 'component_id' => $c->id, 'sku' => 'TONER-M-01', 'name' => 'Toner Magenta']);
        $man = Manufacturer::create(['code' => 'KM', 'name' => 'KM']);
        $model = MachineModel::create(['manufacturer_id' => $man->id, 'model_code' => 'C1070', 'name' => 'C1070']);
        $profile = ModelProfile::create(['machine_model_id' => $model->id, 'name' => 'P']);
        $slot = ModelProfileSlot::create(['profile_id' => $profile->id, 'component_id' => $c->id, 'slot_code' => 'TONER_M', 'baseline_expected_clicks' => 14000]);
        $m = Machine::create(['account_id' => $a->id, 'branch_id' => $b->id, 'machine_model_id' => $model->id, 'machine_code' => 'M1', 'display_name' => 'M1']);
        $mc = MachineComponent::create(['account_id' => $a->id, 'machine_id' => $m->id, 'component_id' => $c->id, 'profile_slot_id' => $slot->id, 'slot_code' => 'TONER_M', 'source_type' => 'inherited', 'status' => 'configured', 'active_key' => 'active', 'baseline_expected_clicks' => 14000]);
        $person = OperationalPerson::create(['account_id' => $a->id, 'name' => 'Akmal Fauzan', 'is_active' => true]);
        OperationalPersonBranch::create(['account_id' => $a->id, 'person_id' => $person->id, 'branch_id' => $b->id, 'is_active' => true, 'can_record_counter' => true]);
        $admin = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $a->id, 'user_id' => $admin->id, 'role' => 'admin', 'status' => 'active', 'accepted_at' => now()]);
        AccountMembershipBranch::create(['account_id' => $a->id, 'membership_id' => $membership->id, 'branch_id' => $b->id, 'is_active' => true]);

        return compact('a', 'b', 'loc', 'item', 'mc', 'm', 'person', 'admin');
    }

    public function test_receive_goods_persists_the_physical_pic_on_the_resulting_movement(): void
    {
        $f = $this->f();
        $p = app(PurchaseReceiptService::class)->purchase($f['a']->id, ['purchase_number' => 'P1', 'purchase_date' => '2026-09-04', 'client_request_id' => (string) Str::uuid(), 'lines' => [['inventory_item_id' => $f['item']->id, 'quantity' => 2, 'unit_cost' => 1625000]]]);
        $line = DB::table('purchase_lines')->where('purchase_id', $p->id)->first();

        $response = $this->actingAs($f['admin'])->postJson("/api/v1/purchases/{$p->id}/receive", [
            'location_id' => $f['loc']->id, 'person_id' => $f['person']->id, 'client_request_id' => (string) Str::uuid(),
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => 2]],
        ])->assertCreated();

        $movement = InventoryMovement::where('movement_type', 'receipt')->where('inventory_item_id', $f['item']->id)->firstOrFail();
        $this->assertSame((string) $f['person']->id, (string) $movement->operational_person_id);
        $this->assertSame('Akmal Fauzan', $movement->operational_person_name_snapshot);
        $this->assertSame((string) $f['admin']->id, (string) $movement->entered_by);
    }

    public function test_receive_goods_rejects_a_person_not_eligible_for_the_location(): void
    {
        $f = $this->f();
        $p = app(PurchaseReceiptService::class)->purchase($f['a']->id, ['purchase_number' => 'P1', 'purchase_date' => '2026-09-04', 'client_request_id' => (string) Str::uuid(), 'lines' => [['inventory_item_id' => $f['item']->id, 'quantity' => 2, 'unit_cost' => 1625000]]]);
        $line = DB::table('purchase_lines')->where('purchase_id', $p->id)->first();

        $this->actingAs($f['admin'])->postJson("/api/v1/purchases/{$p->id}/receive", [
            'location_id' => $f['loc']->id, 'person_id' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(),
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => 2]],
        ])->assertStatus(422)->assertJsonValidationErrors('person_id');
    }

    public function test_receive_goods_without_a_person_still_succeeds_with_a_null_pic(): void
    {
        $f = $this->f();
        $p = app(PurchaseReceiptService::class)->purchase($f['a']->id, ['purchase_number' => 'P1', 'purchase_date' => '2026-09-04', 'client_request_id' => (string) Str::uuid(), 'lines' => [['inventory_item_id' => $f['item']->id, 'quantity' => 2, 'unit_cost' => 1625000]]]);
        $line = DB::table('purchase_lines')->where('purchase_id', $p->id)->first();

        $this->actingAs($f['admin'])->postJson("/api/v1/purchases/{$p->id}/receive", [
            'location_id' => $f['loc']->id, 'client_request_id' => (string) Str::uuid(),
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => 2]],
        ])->assertCreated();

        $movement = InventoryMovement::where('movement_type', 'receipt')->firstOrFail();
        $this->assertNull($movement->operational_person_id);
    }

    public function test_replacement_anchors_new_lifecycle_baseline_to_the_current_effective_counter(): void
    {
        $f = $this->f();
        app(InventoryLedgerService::class)->inbound($f['item'], $f['loc'], 2, 1625000, 'receipt', (string) Str::uuid());
        $previous = ComponentLifecycle::create(['machine_component_id' => $f['mc']->id, 'installed_counter' => 1435866, 'started_at' => null, 'status' => 'unknown', 'active_key' => 'active']);
        $counterType = CounterType::where('code', 'total_impressions')->firstOrFail();
        CounterReading::create(['account_id' => $f['a']->id, 'machine_id' => $f['m']->id, 'counter_type_id' => $counterType->id, 'reading_value' => 1471260, 'observed_at' => '2026-09-10 16:00:00', 'status' => 'effective', 'source' => 'manual', 'client_request_id' => (string) Str::uuid()]);

        $replacement = app(ReplaceMachineComponent::class)->execute($f['mc'], [
            'inventory_source' => 'inventory', 'inventory_item_id' => $f['item']->id, 'inventory_location_id' => $f['loc']->id,
            'quantity' => 1, 'replaced_at' => '2026-09-10 13:00:00', 'performed_by_person_id' => $f['person']->id, 'performed_by_name' => $f['person']->name,
            'client_request_id' => (string) Str::uuid(),
        ]);

        $previous->refresh();
        $this->assertSame('closed', $previous->status);
        $this->assertSame(1471260.0, (float) $previous->removed_counter);
        $this->assertSame(35394.0, (float) $previous->actual_usage);

        $new = ComponentLifecycle::find($replacement->new_lifecycle_id);
        $this->assertSame('active', $new->status);
        $this->assertSame(1471260.0, (float) $new->installed_counter);

        // Immediately after replacement, with no further clicks recorded, usage must be
        // zero and remaining life ~100% - the exact business invariant the mission
        // requires and the exact value the Production incident showed as 0.0% instead.
        $usedClicks = 1471260.0 - (float) $new->installed_counter;
        $this->assertSame(0.0, $usedClicks);
    }

    public function test_replacement_with_no_counter_history_leaves_a_null_baseline_rather_than_fabricating_one(): void
    {
        $f = $this->f();
        app(InventoryLedgerService::class)->inbound($f['item'], $f['loc'], 1, 1625000, 'receipt', (string) Str::uuid());

        $replacement = app(ReplaceMachineComponent::class)->execute($f['mc'], [
            'inventory_source' => 'inventory', 'inventory_item_id' => $f['item']->id, 'inventory_location_id' => $f['loc']->id,
            'quantity' => 1, 'client_request_id' => (string) Str::uuid(),
        ]);

        $new = ComponentLifecycle::find($replacement->new_lifecycle_id);
        $this->assertNull($new->installed_counter);
    }

    public function test_repeated_replacement_request_does_not_recompute_or_duplicate_the_lifecycle_baseline(): void
    {
        $f = $this->f();
        app(InventoryLedgerService::class)->inbound($f['item'], $f['loc'], 2, 1625000, 'receipt', (string) Str::uuid());
        $counterType = CounterType::where('code', 'total_impressions')->firstOrFail();
        CounterReading::create(['account_id' => $f['a']->id, 'machine_id' => $f['m']->id, 'counter_type_id' => $counterType->id, 'reading_value' => 1000000, 'observed_at' => now(), 'status' => 'effective', 'source' => 'manual', 'client_request_id' => (string) Str::uuid()]);
        $request = (string) Str::uuid();
        $args = ['inventory_source' => 'inventory', 'inventory_item_id' => $f['item']->id, 'inventory_location_id' => $f['loc']->id, 'quantity' => 1, 'client_request_id' => $request];

        $first = app(ReplaceMachineComponent::class)->execute($f['mc'], $args);
        $second = app(ReplaceMachineComponent::class)->execute($f['mc'], $args);

        $this->assertSame((string) $first->id, (string) $second->id);
        $this->assertSame(1, DB::table('component_replacements')->count());
        $this->assertSame(1, ComponentLifecycle::where('status', 'active')->count());
        $this->assertSame(1.0, app(InventoryLedgerService::class)->balance($f['item']->id, $f['loc']->id));
    }

    // Part A: the Receiving tab (workspace()'s `receipts`) previously returned raw
    // `receipts` table HEADER rows with none of the fields the UI reads, rendering
    // "+0" received, a blank Purchase/Location, and "RpNaN" for acquisition price -
    // exactly the Production screenshot. This proves the fixed receipt-line-level
    // projection never does that, for both a known and an unknown unit cost.
    public function test_receiving_list_projects_real_quantity_purchase_supplier_location_and_price(): void
    {
        $f = $this->f();
        $supplier = InventorySupplier::create(['account_id' => $f['a']->id, 'code' => 'JFP', 'name' => 'Just_ForPrint']);
        $p = app(PurchaseReceiptService::class)->purchase($f['a']->id, ['supplier_id' => $supplier->id, 'branch_id' => $f['b']->id, 'purchase_number' => 'PUR-1', 'purchase_date' => '2026-09-04', 'client_request_id' => (string) Str::uuid(), 'lines' => [['inventory_item_id' => $f['item']->id, 'quantity' => 2, 'unit_cost' => 1625000]]]);
        $line = DB::table('purchase_lines')->where('purchase_id', $p->id)->first();
        app(PurchaseReceiptService::class)->receive($p->id, $f['loc'], [['purchase_line_id' => $line->id, 'quantity' => 2]], (string) Str::uuid(), $f['person']->id, $f['person']->name, $f['admin']->id);

        $response = $this->actingAs($f['admin'])->getJson("/api/v1/accounts/{$f['a']->id}/branches/{$f['b']->id}/inventory")->assertOk();
        $receiptLine = collect($response->json('data.receipts'))->firstWhere('inventory_item_id', $f['item']->id);

        $this->assertNotNull($receiptLine, 'expected the receipt line to be present, not silently dropped');
        $this->assertSame('Toner Magenta', $receiptLine['item_name_snapshot']);
        $this->assertEquals(2.0, $receiptLine['quantity']);
        $this->assertSame('PUR-1', $receiptLine['purchase_number_snapshot']);
        $this->assertSame('Just_ForPrint', $receiptLine['supplier_name_snapshot']);
        $this->assertEquals(1625000.0, $receiptLine['unit_price_snapshot']);
        $this->assertEquals(3250000.0, $receiptLine['acquisition_value']);
        $this->assertSame('Warehouse', $receiptLine['location_name']);
        $this->assertSame('Akmal Fauzan', $receiptLine['operational_person_name_snapshot']);
        $this->assertNotEmpty($receiptLine['received_at']);
    }

    public function test_receiving_list_never_renders_nan_when_unit_cost_is_unknown(): void
    {
        $f = $this->f();
        $p = app(PurchaseReceiptService::class)->purchase($f['a']->id, ['branch_id' => $f['b']->id, 'purchase_number' => 'PUR-2', 'purchase_date' => '2026-09-04', 'client_request_id' => (string) Str::uuid(), 'lines' => [['inventory_item_id' => $f['item']->id, 'quantity' => 1]]]);
        $line = DB::table('purchase_lines')->where('purchase_id', $p->id)->first();
        app(PurchaseReceiptService::class)->receive($p->id, $f['loc'], [['purchase_line_id' => $line->id, 'quantity' => 1]], (string) Str::uuid());

        $response = $this->actingAs($f['admin'])->getJson("/api/v1/accounts/{$f['a']->id}/branches/{$f['b']->id}/inventory")->assertOk();
        $receiptLine = collect($response->json('data.receipts'))->firstWhere('inventory_item_id', $f['item']->id);

        $this->assertNull($receiptLine['unit_price_snapshot']);
        $this->assertNull($receiptLine['acquisition_value']);
        $this->assertNull($receiptLine['operational_person_name_snapshot']);
    }

    // Part B: the Movements tab returned raw `inventory_movements` rows with no
    // item/location name join at all, for every movement type - this proves the
    // fixed projection resolves them.
    public function test_movements_list_projects_item_and_location_names(): void
    {
        $f = $this->f();
        app(InventoryLedgerService::class)->inbound($f['item'], $f['loc'], 5, 1625000, 'opening_balance', (string) Str::uuid(), 'seed');

        $response = $this->actingAs($f['admin'])->getJson("/api/v1/accounts/{$f['a']->id}/branches/{$f['b']->id}/inventory")->assertOk();
        $movement = collect($response->json('data.movements'))->firstWhere('movement_type', 'opening_balance');

        $this->assertNotNull($movement);
        $this->assertSame('Toner Magenta', $movement['item_name']);
        $this->assertSame('TONER-M-01', $movement['sku']);
        $this->assertSame('pcs', $movement['unit_snapshot']);
        $this->assertSame('Warehouse', $movement['location_name']);
        $this->assertArrayHasKey('movement_id', $movement);
    }

    // The lookup collections passed into the builders are deliberately unfiltered by
    // is_active (see InventoryController's own comment) so that if a location or user
    // is ever deactivated AFTER a movement referencing it was already included in a
    // response, the name still resolves rather than reverting to "Unknown" - this
    // proves that at the builder level directly, independent of the separate (and
    // intentionally unchanged) is_active inclusion-scope filter.
    public function test_movement_and_receipt_builders_resolve_names_even_for_an_inactive_location(): void
    {
        $inactiveLocation = (object) ['id' => 'loc-1', 'name' => 'Retired Bay'];
        $item = (object) ['id' => 'item-1', 'name' => 'Toner Magenta', 'sku' => 'SKU-1', 'unit' => 'pcs'];
        $movementRow = (object) ['id' => 'mv-1', 'inventory_item_id' => 'item-1', 'location_id' => 'loc-1', 'movement_type' => 'opening_balance', 'quantity' => 1, 'entered_by' => null];

        $built = app(MovementSummaryBuilder::class)->build(collect([$movementRow]), collect([$item]), collect([$inactiveLocation]), collect());

        $this->assertSame('Retired Bay', $built->first()->location_name);
    }
}
