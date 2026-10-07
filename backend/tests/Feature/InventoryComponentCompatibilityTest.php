<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Branch;
use App\Models\ComponentCatalog;
use App\Models\ComponentLifecycle;
use App\Models\InventoryComponentCompatibility;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\Machine;
use App\Models\MachineComponent;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\ModelProfile;
use App\Models\ModelProfileSlot;
use App\Models\User;
use App\Services\InventoryComponentCompatibilityService;
use App\Services\ReplaceMachineComponent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * Generic part <-> component compatibility: the Phase 1 data-foundation
 * tests (table/model/service/read API scoping). Phase 1 shipped this
 * feature-dark - ReplaceMachineComponent still enforced the old single
 * component_id equality/null-wildcard rule at the time these tests were
 * written. Phase 2 (see InventoryComponentCompatibilityEnforcementTest) has
 * since switched enforcement to this table exclusively, so the three
 * `test_*` methods at the bottom of this file were updated in place to
 * assert the new behavior rather than left stale.
 */
class InventoryComponentCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $account = Account::create(['code' => 'CG', 'name' => 'Cipta Grafika']);
        $branch = Branch::create(['account_id' => $account->id, 'code' => 'MAIN', 'name' => 'Main']);
        $otherAccount = Account::create(['code' => 'OTHER', 'name' => 'Other Tenant']);

        $generic = InventoryItem::create(['account_id' => $account->id, 'component_id' => null, 'sku' => 'GEN-CORONA', 'name' => 'Charging Corona']);
        $global = ComponentCatalog::create(['code' => 'global_part', 'name' => 'Global Part', 'is_active' => true]);
        $k = ComponentCatalog::create(['account_id' => $account->id, 'code' => 'corona_k', 'name' => 'Charging Corona K', 'is_active' => true]);
        $c = ComponentCatalog::create(['account_id' => $account->id, 'code' => 'corona_c', 'name' => 'Charging Corona C', 'is_active' => true]);
        $otherComponent = ComponentCatalog::create(['account_id' => $otherAccount->id, 'code' => 'other_part', 'name' => 'Other Tenant Part', 'is_active' => true]);
        $otherItem = InventoryItem::create(['account_id' => $otherAccount->id, 'component_id' => null, 'sku' => 'OTHER-01', 'name' => 'Other Tenant Item']);

        $user = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);

        return compact('account', 'branch', 'otherAccount', 'generic', 'global', 'k', 'c', 'otherComponent', 'otherItem', 'user');
    }

    // 1. A compatibility row can link one inventory item to one component catalog.
    public function test_one_compatibility_row_links_item_to_component(): void
    {
        $f = $this->fixture();
        $row = app(InventoryComponentCompatibilityService::class)->link($f['generic'], $f['k']->id);

        $this->assertSame((string) $f['generic']->id, $row->inventory_item_id);
        $this->assertSame((string) $f['k']->id, $row->component_id);
        $this->assertSame((string) $f['account']->id, $row->account_id);
        $this->assertTrue($row->is_active);
        $this->assertTrue($f['generic']->compatibilities()->whereKey($row->id)->exists());
        $this->assertTrue($f['k']->compatibilities()->whereKey($row->id)->exists());
    }

    // 2. One inventory item can have multiple component compatibilities.
    public function test_one_item_can_have_multiple_compatibilities(): void
    {
        $f = $this->fixture();
        $service = app(InventoryComponentCompatibilityService::class);
        $service->link($f['generic'], $f['k']->id);
        $service->link($f['generic'], $f['c']->id);
        $service->link($f['generic'], $f['global']->id);

        $this->assertSame(3, $f['generic']->compatibilities()->count());
        $this->assertEqualsCanonicalizing(
            [(string) $f['k']->id, (string) $f['c']->id, (string) $f['global']->id],
            $f['generic']->compatibilities()->pluck('component_id')->all(),
        );
    }

    // 3. A duplicate item+component pair is rejected.
    public function test_duplicate_pair_is_rejected(): void
    {
        $f = $this->fixture();
        $service = app(InventoryComponentCompatibilityService::class);
        $service->link($f['generic'], $f['k']->id);

        $this->expectException(QueryException::class);
        $service->link($f['generic'], $f['k']->id);
    }

    // 4. Cross-account mapping is rejected: an item from one account can never
    // be linked to a component that belongs to a different, specific account.
    public function test_cross_account_mapping_is_rejected(): void
    {
        $f = $this->fixture();
        $service = app(InventoryComponentCompatibilityService::class);

        $this->expectException(ValidationException::class);
        $service->link($f['generic'], $f['otherComponent']->id);
    }

    // 5. Global/account component visibility follows the existing scoped rules:
    // a global (account_id null) component is linkable from any account; an
    // account-owned component is only linkable from its own account.
    public function test_global_and_owned_component_visibility_follows_existing_scoped_rules(): void
    {
        $f = $this->fixture();
        $service = app(InventoryComponentCompatibilityService::class);

        $global = $service->link($f['generic'], $f['global']->id);
        $owned = $service->link($f['generic'], $f['k']->id);
        $this->assertNotNull($global->id);
        $this->assertNotNull($owned->id);

        $this->expectException(ValidationException::class);
        $service->link($f['otherItem'], $f['k']->id);
    }

    // 6. An inactive/archived compatibility row is excluded from active read results.
    public function test_inactive_compatibility_excluded_from_active_read(): void
    {
        $f = $this->fixture();
        $row = app(InventoryComponentCompatibilityService::class)->link($f['generic'], $f['k']->id);
        $row->update(['is_active' => false, 'archived_at' => now()]);

        $this->assertSame(0, InventoryComponentCompatibility::where('account_id', $f['account']->id)->where('is_active', true)->count());
        $this->assertSame(1, InventoryComponentCompatibility::where('account_id', $f['account']->id)->count(), 'archiving must not hard-delete the row');

        $response = $this->actingAs($f['user'])->getJson("/api/v1/accounts/{$f['account']->id}/branches/{$f['branch']->id}/inventory")->assertOk();
        $this->assertSame([], $response->json('data.compatibilities'));
    }

    // 7. The authenticated account cannot read another account's mappings
    // through the reused workspace payload.
    public function test_account_cannot_read_another_accounts_mappings(): void
    {
        $f = $this->fixture();
        app(InventoryComponentCompatibilityService::class)->link($f['generic'], $f['k']->id);

        $otherBranch = Branch::create(['account_id' => $f['otherAccount']->id, 'code' => 'MAIN', 'name' => 'Main']);
        $otherUser = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $f['otherAccount']->id, 'user_id' => $otherUser->id, 'role' => 'owner', 'status' => 'active']);
        app(InventoryComponentCompatibilityService::class)->link($f['otherItem'], $f['otherComponent']->id);

        $ownResponse = $this->actingAs($f['user'])->getJson("/api/v1/accounts/{$f['account']->id}/branches/{$f['branch']->id}/inventory")->assertOk();
        $this->assertCount(1, $ownResponse->json('data.compatibilities'));
        $this->assertSame((string) $f['account']->id, $ownResponse->json('data.compatibilities.0.account_id'));

        $otherResponse = $this->actingAs($otherUser)->getJson("/api/v1/accounts/{$f['otherAccount']->id}/branches/{$otherBranch->id}/inventory")->assertOk();
        $this->assertCount(1, $otherResponse->json('data.compatibilities'));
        $this->assertSame((string) $f['otherAccount']->id, $otherResponse->json('data.compatibilities.0.account_id'));
    }

    private function replaceFixture(array $f): array
    {
        $loc = InventoryLocation::create(['account_id' => $f['account']->id, 'branch_id' => $f['branch']->id, 'code' => 'WH', 'name' => 'Warehouse']);
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'KM']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'C', 'name' => 'C']);
        $profile = ModelProfile::create(['machine_model_id' => $model->id, 'name' => 'P']);
        $slot = ModelProfileSlot::create(['profile_id' => $profile->id, 'component_id' => $f['k']->id, 'slot_code' => 'K']);
        $machine = Machine::create(['account_id' => $f['account']->id, 'branch_id' => $f['branch']->id, 'machine_model_id' => $model->id, 'machine_code' => 'M1', 'display_name' => 'M1', 'status' => 'active']);
        $mc = MachineComponent::create(['account_id' => $f['account']->id, 'machine_id' => $machine->id, 'component_id' => $f['k']->id, 'profile_slot_id' => $slot->id, 'slot_code' => 'K', 'source_type' => 'inherited', 'status' => 'configured', 'active_key' => 'active']);
        ComponentLifecycle::create(['machine_component_id' => $mc->id, 'started_at' => now()->subDays(10), 'status' => 'active', 'active_key' => 'active', 'source' => 'manual']);
        app(\App\Services\InventoryLedgerService::class)->inbound($f['generic'], $loc, 5, 1000, 'opening_balance', (string) Str::uuid(), 'opening');

        return compact('loc', 'mc');
    }

    // 8a. Phase 2 superseded this: an item whose single component_id matches
    // the machine component no longer passes on that fact alone - it now
    // requires the explicit compatibility row an item like this would get
    // from the Phase 2 generic backfill. See
    // InventoryComponentCompatibilityEnforcementTest for the full Phase 2
    // enforcement suite; this test documents the new requirement in place.
    public function test_matching_component_id_alone_no_longer_suffices_without_an_explicit_compatibility_row(): void
    {
        $f = $this->fixture();
        $matched = InventoryItem::create(['account_id' => $f['account']->id, 'component_id' => $f['k']->id, 'sku' => 'CORONA-K', 'name' => 'Charging Corona K']);
        $r = $this->replaceFixture($f);
        app(\App\Services\InventoryLedgerService::class)->inbound($matched, $r['loc'], 5, 1000, 'opening_balance', (string) Str::uuid(), 'opening');

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('[NO_COMPATIBLE_INVENTORY_MAPPING]');
        app(ReplaceMachineComponent::class)->execute($r['mc'], [
            'inventory_source' => 'inventory', 'inventory_item_id' => $matched->id, 'inventory_location_id' => $r['loc']->id,
            'quantity' => 1, 'client_request_id' => (string) Str::uuid(),
        ]);
    }

    // 8b. A mismatched item is still rejected under Phase 2 enforcement -
    // now because no compatibility row exists for this pair at all, not
    // because of a component_id inequality check.
    public function test_mismatched_item_without_a_compatibility_row_is_rejected(): void
    {
        $f = $this->fixture();
        $mismatched = InventoryItem::create(['account_id' => $f['account']->id, 'component_id' => $f['c']->id, 'sku' => 'CORONA-C', 'name' => 'Charging Corona C']);
        $r = $this->replaceFixture($f);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('[NO_COMPATIBLE_INVENTORY_MAPPING]');
        app(ReplaceMachineComponent::class)->execute($r['mc'], [
            'inventory_source' => 'inventory', 'inventory_item_id' => $mismatched->id, 'inventory_location_id' => $r['loc']->id,
            'quantity' => 1, 'client_request_id' => (string) Str::uuid(),
        ]);
    }

    // 8c. NULL_COMPONENT wildcard CLOSED (Phase 2): a NULL component_id item
    // with no explicit compatibility row is now rejected exactly like any
    // other unmapped item - Phase 1's "zero historical usage" finding is
    // exactly what made closing this safe.
    public function test_null_component_id_wildcard_is_closed_without_an_explicit_compatibility_row(): void
    {
        $f = $this->fixture();
        $r = $this->replaceFixture($f);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('[NO_COMPATIBLE_INVENTORY_MAPPING]');
        app(ReplaceMachineComponent::class)->execute($r['mc'], [
            'inventory_source' => 'inventory', 'inventory_item_id' => $f['generic']->id, 'inventory_location_id' => $r['loc']->id,
            'quantity' => 1, 'client_request_id' => (string) Str::uuid(),
        ]);
    }
}
