<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Branch;
use App\Models\ComponentCatalog;
use App\Models\ComponentLifecycle;
use App\Models\ComponentReplacement;
use App\Models\InventoryComponentCompatibility;
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
use App\Services\InventoryComponentCompatibilityService;
use App\Services\InventoryLedgerService;
use App\Services\ReplaceMachineComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * Phase 2: ReplaceMachineComponent's inventory-backed compatibility check now
 * requires an explicit, active inventory_component_compatibilities row
 * (component_catalogs is the locked compatibility target) instead of the old
 * single-FK component_id equality/null-wildcard rule. No color-specific code
 * exists anywhere here - "Charging Corona" is only ever test fixture data,
 * never a conditional in the enforcement path itself.
 */
class InventoryComponentCompatibilityEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $account = Account::create(['code' => 'CG', 'name' => 'Cipta Grafika']);
        $branch = Branch::create(['account_id' => $account->id, 'code' => 'MAIN', 'name' => 'Main']);
        $loc = InventoryLocation::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'code' => 'WH', 'name' => 'Warehouse']);
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'KM']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'C', 'name' => 'C']);
        $profile = ModelProfile::create(['machine_model_id' => $model->id, 'name' => 'P']);

        $components = [];
        $machineComponents = [];
        foreach (['K', 'C', 'M', 'Y'] as $color) {
            $components[$color] = ComponentCatalog::create(['account_id' => $account->id, 'code' => 'corona_'.strtolower($color), 'name' => "Charging Corona {$color} Def", 'is_active' => true]);
            $slot = ModelProfileSlot::create(['profile_id' => $profile->id, 'component_id' => $components[$color]->id, 'slot_code' => $color]);
            $machine = Machine::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => "M-{$color}", 'display_name' => "M-{$color}", 'status' => 'active']);
            $mc = MachineComponent::create(['account_id' => $account->id, 'machine_id' => $machine->id, 'component_id' => $components[$color]->id, 'profile_slot_id' => $slot->id, 'slot_code' => $color, 'source_type' => 'inherited', 'status' => 'configured', 'active_key' => 'active']);
            ComponentLifecycle::create(['machine_component_id' => $mc->id, 'started_at' => now()->subDays(10), 'status' => 'active', 'active_key' => 'active', 'source' => 'manual']);
            $machineComponents[$color] = $mc;
        }

        $generic = InventoryItem::create(['account_id' => $account->id, 'component_id' => null, 'sku' => 'GEN-CORONA', 'name' => 'Charging Corona', 'is_active' => true]);
        app(InventoryLedgerService::class)->inbound($generic, $loc, 2, 1000, 'opening_balance', (string) Str::uuid(), 'opening');

        $user = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);

        return compact('account', 'branch', 'loc', 'components', 'machineComponents', 'generic', 'user');
    }

    private function replace(array $f, MachineComponent $mc, string $itemId, ?string $requestId = null): ComponentReplacement
    {
        return app(ReplaceMachineComponent::class)->execute($mc, [
            'inventory_source' => 'inventory', 'inventory_item_id' => $itemId, 'inventory_location_id' => $f['loc']->id,
            'quantity' => 1, 'client_request_id' => $requestId ?? (string) Str::uuid(),
        ]);
    }

    // 1-4 & 5. The SAME generic item, mapped to all four CMYK positions,
    // successfully replaces K, then C, then M, then Y - proving one item
    // supports multiple component definitions with no per-color code.
    public function test_one_generic_item_compatible_with_all_four_cmyk_positions_can_replace_each(): void
    {
        $f = $this->fixture();
        $service = app(InventoryComponentCompatibilityService::class);
        foreach (['K', 'C', 'M', 'Y'] as $color) {
            $service->link($f['generic'], $f['components'][$color]->id);
        }
        app(InventoryLedgerService::class)->inbound($f['generic'], $f['loc'], 2, 1000, 'opening_balance', (string) Str::uuid(), 'top up');
        // balance now 4, enough for all four 1-unit replacements below

        foreach (['K', 'C', 'M', 'Y'] as $color) {
            $replacement = $this->replace($f, $f['machineComponents'][$color], $f['generic']->id);
            $this->assertSame((string) $f['generic']->id, $replacement->inventory_item_id);
            $this->assertNotNull($replacement->inventory_movement_id);
        }
        $this->assertSame(0.0, app(InventoryLedgerService::class)->balance($f['generic']->id, $f['loc']->id));
        $this->assertSame(4, InventoryMovement::where('movement_type', 'replacement_consumption')->count());
    }

    // 6. An item without ANY compatibility mapping is rejected.
    public function test_item_without_compatibility_mapping_is_rejected(): void
    {
        $f = $this->fixture();

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('[NO_COMPATIBLE_INVENTORY_MAPPING]');
        $this->replace($f, $f['machineComponents']['K'], $f['generic']->id);
    }

    // 7. component_id = NULL with no mapping is rejected - the wildcard is closed.
    public function test_null_component_id_item_without_mapping_is_rejected(): void
    {
        $f = $this->fixture();
        $this->assertNull($f['generic']->component_id);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('[NO_COMPATIBLE_INVENTORY_MAPPING]');
        $this->replace($f, $f['machineComponents']['K'], $f['generic']->id);
    }

    // 8. An inactive/archived compatibility row no longer counts.
    public function test_inactive_compatibility_is_rejected(): void
    {
        $f = $this->fixture();
        $row = app(InventoryComponentCompatibilityService::class)->link($f['generic'], $f['components']['K']->id);
        $row->update(['is_active' => false, 'archived_at' => now()]);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('[NO_COMPATIBLE_INVENTORY_MAPPING]');
        $this->replace($f, $f['machineComponents']['K'], $f['generic']->id);
    }

    // 9. A compatibility row that (hypothetically, bypassing the creation
    // service) points cross-account is still blocked by the independent,
    // preserved account/tenant scope check - defense in depth, not relying
    // on the compatibility table alone for tenant isolation.
    public function test_cross_account_compatibility_row_is_still_blocked_by_the_preserved_scope_check(): void
    {
        $f = $this->fixture();
        $otherAccount = Account::create(['code' => 'OTHER', 'name' => 'Other']);
        $otherItem = InventoryItem::create(['account_id' => $otherAccount->id, 'component_id' => null, 'sku' => 'OTHER-01', 'name' => 'Other Item', 'is_active' => true]);
        InventoryComponentCompatibility::create(['account_id' => $otherAccount->id, 'inventory_item_id' => $otherItem->id, 'component_id' => $f['components']['K']->id, 'is_active' => true]);

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('inventory scope does not match component account');
        $this->replace($f, $f['machineComponents']['K'], $otherItem->id);
    }

    // 10. Insufficient stock is rejected and the existing lifecycle is untouched.
    public function test_insufficient_stock_is_rejected_and_lifecycle_unchanged(): void
    {
        $f = $this->fixture();
        app(InventoryComponentCompatibilityService::class)->link($f['generic'], $f['components']['K']->id);
        $mc = $f['machineComponents']['K'];
        $activeBefore = $mc->lifecycles()->where('status', 'active')->first();

        try {
            app(ReplaceMachineComponent::class)->execute($mc, [
                'inventory_source' => 'inventory', 'inventory_item_id' => $f['generic']->id, 'inventory_location_id' => $f['loc']->id,
                'quantity' => 100, 'client_request_id' => (string) Str::uuid(),
            ]);
            $this->fail('expected insufficient stock to reject');
        } catch (ConflictHttpException $e) {
            $this->assertSame('insufficient stock', $e->getMessage());
        }

        $activeBefore->refresh();
        $this->assertSame('active', $activeBefore->status);
        $this->assertSame(1, ComponentLifecycle::where('machine_component_id', $mc->id)->count(), 'no new lifecycle was created');
        $this->assertSame(0, ComponentReplacement::count());
    }

    // 11. A failure after inventory consumption (surgically injected at the
    // new-lifecycle INSERT, independent of any application-level validation)
    // rolls back the ENTIRE transaction, including the already-executed
    // inventory outbound movement - proving the atomic boundary is real, not
    // just "validate first, then assume the rest is safe".
    public function test_a_late_failure_after_inventory_consumption_rolls_back_the_whole_transaction(): void
    {
        $f = $this->fixture();
        app(InventoryComponentCompatibilityService::class)->link($f['generic'], $f['components']['K']->id);
        $mc = $f['machineComponents']['K'];
        $balanceBefore = app(InventoryLedgerService::class)->balance($f['generic']->id, $f['loc']->id);

        DB::listen(function ($query) {
            if (stripos($query->sql, 'insert into') !== false && stripos($query->sql, 'component_lifecycles') !== false) {
                throw new \RuntimeException('SIMULATED_LIFECYCLE_WRITE_FAILURE');
            }
        });

        try {
            app(ReplaceMachineComponent::class)->execute($mc, [
                'inventory_source' => 'inventory', 'inventory_item_id' => $f['generic']->id, 'inventory_location_id' => $f['loc']->id,
                'quantity' => 1, 'client_request_id' => (string) Str::uuid(),
            ]);
            $this->fail('expected the simulated failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('SIMULATED_LIFECYCLE_WRITE_FAILURE', $e->getMessage());
        } finally {
            DB::getEventDispatcher()->forget('Illuminate\Database\Events\QueryExecuted');
        }

        $this->assertSame($balanceBefore, app(InventoryLedgerService::class)->balance($f['generic']->id, $f['loc']->id), 'the inventory consumption must roll back with the rest of the transaction');
        $this->assertSame(0, InventoryMovement::where('movement_type', 'replacement_consumption')->count());
        $this->assertSame(0, ComponentReplacement::count());
        $this->assertSame(1, ComponentLifecycle::where('machine_component_id', $mc->id)->count(), 'no partial new lifecycle survived');
    }

    // 12. Duplicate submission (same client_request_id) remains idempotent -
    // the exact same replacement is returned, not a second consumption.
    public function test_duplicate_submission_is_idempotent(): void
    {
        $f = $this->fixture();
        app(InventoryComponentCompatibilityService::class)->link($f['generic'], $f['components']['K']->id);
        $requestId = (string) Str::uuid();

        $first = $this->replace($f, $f['machineComponents']['K'], $f['generic']->id, $requestId);
        $second = $this->replace($f, $f['machineComponents']['K'], $f['generic']->id, $requestId);

        $this->assertSame((string) $first->id, (string) $second->id);
        $this->assertSame(1.0, app(InventoryLedgerService::class)->balance($f['generic']->id, $f['loc']->id));
        $this->assertSame(1, InventoryMovement::where('movement_type', 'replacement_consumption')->count());
    }

    // 13. Concurrent replacement protection remains - proven in
    // InventoryMysqlConcurrencyTest::test_two_competing_component_replacements_cannot_double_spend_one_unit
    // (merged there, not a separate test file, so its real permanently-
    // committed subprocess writes land in that file's already-proven-safe
    // position in the whole suite's run order instead of leaking into
    // unrelated tests' unscoped global counts - see that file's docblock).
    // 14. external_untracked replacement remains valid without any inventory
    // mapping at all - compatibility enforcement applies to inventory-backed
    // replacement only.
    public function test_external_untracked_replacement_remains_valid_without_a_mapping(): void
    {
        $f = $this->fixture();
        $mc = $f['machineComponents']['K'];

        $replacement = app(ReplaceMachineComponent::class)->execute($mc, [
            'inventory_source' => 'external_untracked', 'external_reason' => 'Sourced from another branch, untracked',
            'client_request_id' => (string) Str::uuid(),
        ]);

        $this->assertNull($replacement->inventory_item_id);
        $this->assertNull($replacement->inventory_movement_id);
        $this->assertSame('external_untracked', $replacement->inventory_source);
    }

    // 15. Existing non-null component-linked items remain usable after the
    // Phase 2 generic backfill runs (the exact transition this phase exists
    // to protect).
    public function test_existing_component_linked_item_remains_usable_after_backfill(): void
    {
        $f = $this->fixture();
        $matched = InventoryItem::create(['account_id' => $f['account']->id, 'component_id' => $f['components']['K']->id, 'sku' => 'CORONA-K', 'name' => 'Charging Corona Black', 'is_active' => true]);
        app(InventoryLedgerService::class)->inbound($matched, $f['loc'], 5, 1000, 'opening_balance', (string) Str::uuid(), 'opening');

        app(\App\Services\InventoryComponentCompatibilityBackfillService::class)->run(true);

        $replacement = $this->replace($f, $f['machineComponents']['K'], $matched->id);
        $this->assertSame((string) $matched->id, $replacement->inventory_item_id);
    }
}
