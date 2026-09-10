<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Branch;
use App\Models\ComponentCatalog;
use App\Models\ComponentReplacement;
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
use App\Services\OperationalPersonEligibilityService;
use App\Services\ReplaceMachineComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class M2_15_InventoryComponentPicLinkageTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $a = Account::create(['code' => 'M215', 'name' => 'M2.15']);
        $b = Branch::create(['account_id' => $a->id, 'code' => 'MAIN', 'name' => 'Main']);
        $loc = InventoryLocation::create(['account_id' => $a->id, 'branch_id' => $b->id, 'code' => 'WH', 'name' => 'Warehouse']);

        $operator = OperationalPerson::create(['account_id' => $a->id, 'name' => 'Operator One', 'is_active' => true]);
        OperationalPersonBranch::create(['account_id' => $a->id, 'person_id' => $operator->id, 'branch_id' => $b->id, 'is_active' => true, 'can_record_counter' => true]);

        $errorOnly = OperationalPerson::create(['account_id' => $a->id, 'name' => 'Incident Only', 'is_active' => true]);
        OperationalPersonBranch::create(['account_id' => $a->id, 'person_id' => $errorOnly->id, 'branch_id' => $b->id, 'is_active' => true, 'can_record_counter' => false]);

        $archived = OperationalPerson::create(['account_id' => $a->id, 'name' => 'Archived Operator', 'is_active' => false]);
        OperationalPersonBranch::create(['account_id' => $a->id, 'person_id' => $archived->id, 'branch_id' => $b->id, 'is_active' => true, 'can_record_counter' => true]);

        $tonerYCatalog = ComponentCatalog::create(['code' => 'toner_y', 'name' => 'Toner Yellow Catalog']);
        $tonerMCatalog = ComponentCatalog::create(['code' => 'toner_m', 'name' => 'Toner Magenta Catalog']);

        $tonerYItem = InventoryItem::create(['account_id' => $a->id, 'component_id' => $tonerYCatalog->id, 'sku' => 'TONER-Y', 'name' => 'Toner Yellow']);
        $tonerMItem = InventoryItem::create(['account_id' => $a->id, 'component_id' => $tonerMCatalog->id, 'sku' => 'TONER-M', 'name' => 'Toner Magenta']);
        $unlinkedItem = InventoryItem::create(['account_id' => $a->id, 'component_id' => null, 'sku' => 'LEGACY', 'name' => 'Legacy Unlinked']);
        $archivedItem = InventoryItem::create(['account_id' => $a->id, 'component_id' => $tonerYCatalog->id, 'sku' => 'TONER-Y-OLD', 'name' => 'Old Toner Yellow', 'is_active' => false]);

        $man = Manufacturer::create(['code' => 'KM', 'name' => 'KM']);
        $model = MachineModel::create(['manufacturer_id' => $man->id, 'model_code' => 'C', 'name' => 'C']);
        $profile = ModelProfile::create(['machine_model_id' => $model->id, 'name' => 'P']);
        $slot = ModelProfileSlot::create(['profile_id' => $profile->id, 'component_id' => $tonerYCatalog->id, 'slot_code' => 'TONER_Y']);
        $machine = Machine::create(['account_id' => $a->id, 'branch_id' => $b->id, 'machine_model_id' => $model->id, 'machine_code' => 'M1', 'display_name' => 'M1']);
        $mc = MachineComponent::create(['account_id' => $a->id, 'machine_id' => $machine->id, 'component_id' => $tonerYCatalog->id, 'profile_slot_id' => $slot->id, 'slot_code' => 'TONER_Y', 'source_type' => 'inherited', 'status' => 'configured', 'active_key' => 'active']);

        $user = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $a->id, 'user_id' => $user->id, 'role' => 'admin', 'status' => 'active']);
        AccountMembershipBranch::create(['account_id' => $a->id, 'membership_id' => $membership->id, 'branch_id' => $b->id, 'is_active' => true]);

        return compact('a', 'b', 'loc', 'operator', 'errorOnly', 'archived', 'tonerYCatalog', 'tonerMCatalog', 'tonerYItem', 'tonerMItem', 'unlinkedItem', 'archivedItem', 'machine', 'mc', 'user');
    }

    public function test_inventory_workspace_people_match_daily_canonical_operator_population(): void
    {
        $f = $this->fixture();
        $canonical = app(OperationalPersonEligibilityService::class)->forMachine($f['machine'])->pluck('id')->map(fn ($id) => (string) $id)->sort()->values();

        $response = $this->actingAs($f['user'])->getJson("/api/v1/accounts/{$f['a']->id}/branches/{$f['b']->id}/inventory")->assertOk();
        $peopleIds = collect($response->json('data.people'))->pluck('id')->sort()->values();

        $this->assertEquals($canonical->all(), $peopleIds->all());
        $this->assertContains((string) $f['operator']->id, $peopleIds->all());
        $this->assertNotContains((string) $f['errorOnly']->id, $peopleIds->all());
        $this->assertNotContains((string) $f['archived']->id, $peopleIds->all());
    }

    public function test_component_replacement_operator_selectable_and_non_operator_rejected(): void
    {
        $f = $this->fixture();
        $eligible = app(OperationalPersonEligibilityService::class)->eligible($f['machine'], (string) $f['operator']->id);
        $this->assertNotNull($eligible);
        $this->assertSame($f['operator']->name, $eligible->name);

        $this->assertNull(app(OperationalPersonEligibilityService::class)->eligible($f['machine'], (string) $f['errorOnly']->id));
    }

    public function test_opening_balance_known_cost_produces_correct_cost_basis(): void
    {
        $f = $this->fixture();
        app(InventoryLedgerService::class)->inbound($f['tonerYItem'], $f['loc'], 2, 1625000, 'opening_balance', (string) Str::uuid(), 'Opening balance', '2026-09-01 08:00:00');

        $response = $this->actingAs($f['user'])->getJson("/api/v1/accounts/{$f['a']->id}/branches/{$f['b']->id}/inventory")->assertOk();
        $position = collect($response->json('data.costPositions'))->firstWhere('inventory_item_id', (string) $f['tonerYItem']->id);

        $this->assertNotNull($position);
        $this->assertEquals(2.0, $position['known_cost_quantity']);
        $this->assertEquals(0.0, $position['unknown_cost_quantity']);
        $this->assertEquals(3250000.0, $position['known_inventory_cost']);
        $this->assertGreaterThan(0, $position['cost_layer_count']);
    }

    public function test_opening_balance_unknown_cost_never_becomes_fake_known_zero(): void
    {
        $f = $this->fixture();
        app(InventoryLedgerService::class)->inbound($f['tonerMItem'], $f['loc'], 2, null, 'opening_balance', (string) Str::uuid(), 'Opening balance', '2026-09-01 08:00:00');

        $response = $this->actingAs($f['user'])->getJson("/api/v1/accounts/{$f['a']->id}/branches/{$f['b']->id}/inventory")->assertOk();
        $position = collect($response->json('data.costPositions'))->firstWhere('inventory_item_id', (string) $f['tonerMItem']->id);

        $this->assertNotNull($position);
        $this->assertEquals(0.0, $position['known_cost_quantity']);
        $this->assertEquals(2.0, $position['unknown_cost_quantity']);
        $this->assertEquals(0.0, $position['known_inventory_cost']);
    }

    public function test_linkage_only_active_items_matching_component_are_eligible(): void
    {
        $f = $this->fixture();
        $response = $this->actingAs($f['user'])->getJson("/api/v1/accounts/{$f['a']->id}/branches/{$f['b']->id}/inventory")->assertOk();
        $items = collect($response->json('data.items'));

        $tonerYRow = $items->firstWhere('id', (string) $f['tonerYItem']->id);
        $this->assertSame((string) $f['tonerYCatalog']->id, $tonerYRow['component_id']);
        $eligible = $items->filter(fn ($row) => $row['component_id'] === (string) $f['tonerYCatalog']->id)->pluck('id');
        $this->assertContains((string) $f['tonerYItem']->id, $eligible->all());
        $this->assertNotContains((string) $f['unlinkedItem']->id, $eligible->all());
        $this->assertNotContains((string) $f['archivedItem']->id, $items->pluck('id')->all());
    }

    public function test_opening_balance_persists_selected_pic_snapshot(): void
    {
        $f = $this->fixture();
        $response = $this->actingAs($f['user'])->postJson('/api/v1/inventory/opening', [
            'item_id' => $f['tonerYItem']->id,
            'location_id' => $f['loc']->id,
            'quantity' => 2,
            'unit_cost' => 1625000,
            'reason' => 'Opening balance',
            'person_id' => $f['operator']->id,
            'client_request_id' => (string) Str::uuid(),
        ])->assertCreated();

        $movement = InventoryMovement::findOrFail($response->json('data.id'));
        $this->assertSame((string) $f['operator']->id, (string) $movement->operational_person_id);
        $this->assertSame('Operator One', $movement->operational_person_name_snapshot);
        $this->assertSame((string) $f['user']->id, (string) $movement->entered_by);
    }

    public function test_opening_balance_cannot_spoof_entered_by_from_request_body(): void
    {
        $f = $this->fixture();
        $otherUser = User::factory()->create(['status' => 'active']);

        $response = $this->actingAs($f['user'])->postJson('/api/v1/inventory/opening', [
            'item_id' => $f['tonerYItem']->id,
            'location_id' => $f['loc']->id,
            'quantity' => 1,
            'reason' => 'Opening balance',
            'person_id' => $f['operator']->id,
            'entered_by' => $otherUser->id,
            'user_id' => $otherUser->id,
            'client_request_id' => (string) Str::uuid(),
        ])->assertCreated();

        $movement = InventoryMovement::findOrFail($response->json('data.id'));
        $this->assertSame((string) $f['user']->id, (string) $movement->entered_by);
        $this->assertNotSame((string) $otherUser->id, (string) $movement->entered_by);
    }

    public function test_historical_movement_with_null_actor_remains_readable(): void
    {
        $f = $this->fixture();
        $movement = app(InventoryLedgerService::class)->inbound($f['tonerYItem'], $f['loc'], 1, null, 'opening_balance', (string) Str::uuid(), 'Legacy import, no actor known');

        $this->assertNull($movement->entered_by);
        $this->assertNull($movement->operational_person_id);
        $this->assertNull($movement->operational_person_name_snapshot);

        $response = $this->actingAs($f['user'])->getJson("/api/v1/accounts/{$f['a']->id}/branches/{$f['b']->id}/inventory")->assertOk();
        $row = collect($response->json('data.movements'))->firstWhere('id', $movement->id);
        $this->assertNotNull($row);
        $this->assertNull($row['operational_person_id']);
    }

    public function test_opening_balance_rejects_non_operator_pic(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['user'])->postJson('/api/v1/inventory/opening', [
            'item_id' => $f['tonerYItem']->id,
            'location_id' => $f['loc']->id,
            'quantity' => 1,
            'reason' => 'Opening balance',
            'person_id' => $f['errorOnly']->id,
            'client_request_id' => (string) Str::uuid(),
        ])->assertStatus(422);
    }

    public function test_replacement_persists_pic_snapshot_and_consumes_fifo(): void
    {
        $f = $this->fixture();
        app(InventoryLedgerService::class)->inbound($f['tonerYItem'], $f['loc'], 2, 1625000, 'opening_balance', (string) Str::uuid(), 'Opening balance', '2026-09-01 08:00:00');

        $response = $this->actingAs($f['user'])->postJson("/api/v1/machine-components/{$f['mc']->id}/replacements", [
            'inventory_source' => 'inventory',
            'inventory_item_id' => $f['tonerYItem']->id,
            'inventory_location_id' => $f['loc']->id,
            'quantity' => 1,
            'performed_by_person_id' => $f['operator']->id,
            'performed_by_name' => 'Operator One',
            'client_request_id' => (string) Str::uuid(),
        ])->assertCreated();

        $replacement = ComponentReplacement::findOrFail($response->json('data.id'));
        $this->assertSame((string) $f['operator']->id, (string) $replacement->performed_by_person_id);
        $this->assertSame('Operator One', $replacement->performed_by_name_snapshot);
        $this->assertSame((string) $f['user']->id, (string) $replacement->entered_by);
        $this->assertSame(1625000.0, (float) $replacement->consumed_cost);
        $this->assertSame(1.0, app(InventoryLedgerService::class)->balance($f['tonerYItem']->id, $f['loc']->id));

        $consumptionMovement = InventoryMovement::findOrFail($replacement->inventory_movement_id);
        $this->assertSame((string) $f['user']->id, (string) $consumptionMovement->entered_by);
        $this->assertSame((string) $f['operator']->id, (string) $consumptionMovement->operational_person_id);
        $this->assertSame('Operator One', $consumptionMovement->operational_person_name_snapshot);
    }

    public function test_replacement_cannot_spoof_entered_by_from_request_body(): void
    {
        $f = $this->fixture();
        $otherUser = User::factory()->create(['status' => 'active']);
        app(InventoryLedgerService::class)->inbound($f['tonerYItem'], $f['loc'], 2, 1625000, 'opening_balance', (string) Str::uuid(), 'Opening balance', '2026-09-01 08:00:00');

        $response = $this->actingAs($f['user'])->postJson("/api/v1/machine-components/{$f['mc']->id}/replacements", [
            'inventory_source' => 'inventory',
            'inventory_item_id' => $f['tonerYItem']->id,
            'inventory_location_id' => $f['loc']->id,
            'quantity' => 1,
            'performed_by_person_id' => $f['operator']->id,
            'performed_by_name' => 'Operator One',
            'entered_by' => $otherUser->id,
            'client_request_id' => (string) Str::uuid(),
        ])->assertCreated();

        $replacement = ComponentReplacement::findOrFail($response->json('data.id'));
        $this->assertSame((string) $f['user']->id, (string) $replacement->entered_by);
        $this->assertNotSame((string) $otherUser->id, (string) $replacement->entered_by);
    }

    public function test_replacement_rejects_non_operator_pic(): void
    {
        $f = $this->fixture();
        app(InventoryLedgerService::class)->inbound($f['tonerYItem'], $f['loc'], 2, 1625000, 'opening_balance', (string) Str::uuid(), 'Opening balance', '2026-09-01 08:00:00');

        $this->actingAs($f['user'])->postJson("/api/v1/machine-components/{$f['mc']->id}/replacements", [
            'inventory_source' => 'inventory',
            'inventory_item_id' => $f['tonerYItem']->id,
            'inventory_location_id' => $f['loc']->id,
            'quantity' => 1,
            'performed_by_person_id' => $f['errorOnly']->id,
            'client_request_id' => (string) Str::uuid(),
        ])->assertStatus(422);
    }

    public function test_cross_account_pic_is_denied(): void
    {
        $f = $this->fixture();
        $otherAccount = Account::create(['code' => 'OTHER215', 'name' => 'Other']);
        $otherBranch = Branch::create(['account_id' => $otherAccount->id, 'code' => 'OB', 'name' => 'Other Branch']);
        $otherPerson = OperationalPerson::create(['account_id' => $otherAccount->id, 'name' => 'Other Account Operator', 'is_active' => true]);
        OperationalPersonBranch::create(['account_id' => $otherAccount->id, 'person_id' => $otherPerson->id, 'branch_id' => $otherBranch->id, 'is_active' => true, 'can_record_counter' => true]);

        $this->actingAs($f['user'])->postJson('/api/v1/inventory/opening', [
            'item_id' => $f['tonerYItem']->id,
            'location_id' => $f['loc']->id,
            'quantity' => 1,
            'reason' => 'Opening balance',
            'person_id' => $otherPerson->id,
            'client_request_id' => (string) Str::uuid(),
        ])->assertStatus(422);
    }

    public function test_error_flow_person_population_is_unaffected_by_operator_filter(): void
    {
        $f = $this->fixture();
        $response = $this->actingAs($f['user'])->getJson("/api/v1/branches/{$f['b']->id}/operational-people")->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (string) $id);

        $this->assertContains((string) $f['operator']->id, $ids->all());
        $this->assertContains((string) $f['errorOnly']->id, $ids->all());
    }
}
