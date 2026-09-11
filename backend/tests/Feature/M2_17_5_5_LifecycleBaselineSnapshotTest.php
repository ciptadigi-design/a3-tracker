<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\ComponentCatalog;
use App\Models\ComponentLifecycle;
use App\Models\CounterReading;
use App\Models\CounterType;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\Machine;
use App\Models\MachineComponent;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\ModelProfile;
use App\Models\ModelProfileSlot;
use App\Services\ComponentConfigurationService;
use App\Services\InventoryLedgerService;
use App\Services\ReplaceMachineComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * M2.17.5.5: a Production case (CG-TUP-A3-01, TONER_C) showed the Model Profile
 * baseline edited from 16,000 -> 15,000, but Machine Components -> Replace
 * Component still showed Expected Life = 16,000.
 *
 * Root cause: component_lifecycles had NO baseline/expected-life snapshot of its
 * own - "expected life" was always computed live from machine_components (or the
 * profile slot), for BOTH the active lifecycle and every closed historical
 * lifecycle. That happened to look "correct" for TONER_C purely by accident
 * (nothing had ever propagated the new 15,000 onto machine_components), but is
 * fragile: the moment that value changes, an already-installed lifecycle's
 * historical expected-life would retroactively change too - which is wrong.
 *
 * The fix: component_lifecycles.baseline_expected_clicks_snapshot is populated
 * once, at lifecycle-creation time (initial install AND replacement), via
 * ComponentConfigurationService::resolveEffectiveBaseline() - which resolves an
 * 'inherited' component's CURRENT profile slot value fresh (since profile_slot_id
 * + source_type='inherited' is a reliable "no manual override exists" signal),
 * or a 'manual' component's own machine_components.baseline_expected_clicks.
 * Once written, this snapshot is never rewritten by anything.
 */
class M2_17_5_5_LifecycleBaselineSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function f(): array
    {
        $a = Account::create(['code' => 'M2175', 'name' => 'Lifecycle Snapshot']);
        $b = Branch::create(['account_id' => $a->id, 'code' => 'MAIN', 'name' => 'Main']);
        $loc = InventoryLocation::create(['account_id' => $a->id, 'branch_id' => $b->id, 'code' => 'WH', 'name' => 'Warehouse']);
        $c = ComponentCatalog::create(['code' => 'TONER_C', 'name' => 'Toner Cyan']);
        $item = InventoryItem::create(['account_id' => $a->id, 'component_id' => $c->id, 'sku' => 'TONER-C-01', 'name' => 'Toner Cyan']);
        $man = Manufacturer::create(['code' => 'KM', 'name' => 'KM']);
        $model = MachineModel::create(['manufacturer_id' => $man->id, 'model_code' => 'A3', 'name' => 'A3']);
        $profile = ModelProfile::create(['machine_model_id' => $model->id, 'name' => 'P']);
        $slot = ModelProfileSlot::create(['profile_id' => $profile->id, 'component_id' => $c->id, 'slot_code' => 'TONER_C', 'baseline_expected_clicks' => 16000]);
        $m = Machine::create(['account_id' => $a->id, 'branch_id' => $b->id, 'machine_model_id' => $model->id, 'machine_code' => 'CG-TUP-A3-01', 'display_name' => 'CG-TUP-A3-01']);
        $mc = MachineComponent::create(['account_id' => $a->id, 'machine_id' => $m->id, 'component_id' => $c->id, 'profile_slot_id' => $slot->id, 'slot_code' => 'TONER_C', 'source_type' => 'inherited', 'status' => 'configured', 'active_key' => 'active', 'baseline_expected_clicks' => 16000]);

        return compact('a', 'b', 'loc', 'item', 'slot', 'mc', 'm');
    }

    public function test_new_lifecycle_initialization_snapshots_the_current_profile_baseline(): void
    {
        $f = $this->f();

        $lifecycle = app(ComponentConfigurationService::class)->initialize($f['mc'], ['started_at' => now()]);

        $this->assertSame(16000, $lifecycle->baseline_expected_clicks_snapshot);
    }

    public function test_editing_the_model_profile_slot_does_not_mutate_the_active_lifecycles_snapshot(): void
    {
        $f = $this->f();
        $lifecycle = app(ComponentConfigurationService::class)->initialize($f['mc'], ['started_at' => now()]);
        $this->assertSame(16000, $lifecycle->baseline_expected_clicks_snapshot);

        $f['slot']->update(['baseline_expected_clicks' => 15000]);

        $lifecycle->refresh();
        $this->assertSame(16000, $lifecycle->baseline_expected_clicks_snapshot, 'active lifecycle must keep its install-time baseline');
    }

    public function test_editing_the_model_profile_slot_does_not_mutate_a_historical_closed_lifecycles_snapshot(): void
    {
        $f = $this->f();
        app(InventoryLedgerService::class)->inbound($f['item'], $f['loc'], 1, 100000, 'receipt', (string) Str::uuid());
        $counterType = CounterType::where('code', 'total_impressions')->firstOrFail();
        CounterReading::create(['account_id' => $f['a']->id, 'machine_id' => $f['m']->id, 'counter_type_id' => $counterType->id, 'reading_value' => 1000000, 'observed_at' => now(), 'status' => 'effective', 'source' => 'manual', 'client_request_id' => (string) Str::uuid()]);

        $initial = app(ComponentConfigurationService::class)->initialize($f['mc'], ['started_at' => now()->subDays(30)]);
        $this->assertSame(16000, $initial->baseline_expected_clicks_snapshot);

        app(ReplaceMachineComponent::class)->execute($f['mc'], [
            'inventory_source' => 'inventory', 'inventory_item_id' => $f['item']->id, 'inventory_location_id' => $f['loc']->id,
            'quantity' => 1, 'client_request_id' => (string) Str::uuid(),
        ]);

        $f['slot']->update(['baseline_expected_clicks' => 15000]);

        $initial->refresh();
        $this->assertSame('closed', $initial->status);
        $this->assertSame(16000, $initial->baseline_expected_clicks_snapshot, 'closed historical lifecycle must never be rewritten by a later profile edit');
    }

    public function test_replacement_after_a_profile_edit_gives_the_new_lifecycle_the_updated_baseline_while_the_old_one_keeps_its_own(): void
    {
        $f = $this->f();
        app(InventoryLedgerService::class)->inbound($f['item'], $f['loc'], 2, 100000, 'receipt', (string) Str::uuid());
        $counterType = CounterType::where('code', 'total_impressions')->firstOrFail();
        CounterReading::create(['account_id' => $f['a']->id, 'machine_id' => $f['m']->id, 'counter_type_id' => $counterType->id, 'reading_value' => 1000000, 'observed_at' => now(), 'status' => 'effective', 'source' => 'manual', 'client_request_id' => (string) Str::uuid()]);

        // 1. Active lifecycle installed while baseline = 16,000.
        $active = app(ComponentConfigurationService::class)->initialize($f['mc'], ['started_at' => now()->subDays(10)]);
        $this->assertSame(16000, $active->baseline_expected_clicks_snapshot);

        // 2. Model Profile changed to 15,000.
        $f['slot']->update(['baseline_expected_clicks' => 15000]);

        // 3. Before replacement, the active lifecycle's snapshot is unchanged.
        $active->refresh();
        $this->assertSame(16000, $active->baseline_expected_clicks_snapshot);

        // 4. Component is physically replaced.
        $replacement = app(ReplaceMachineComponent::class)->execute($f['mc'], [
            'inventory_source' => 'inventory', 'inventory_item_id' => $f['item']->id, 'inventory_location_id' => $f['loc']->id,
            'quantity' => 1, 'client_request_id' => (string) Str::uuid(),
        ]);

        // 5. Old lifecycle closes and still reports 16,000.
        $active->refresh();
        $this->assertSame('closed', $active->status);
        $this->assertSame(16000, $active->baseline_expected_clicks_snapshot);

        // 6. New lifecycle is active and uses the updated 15,000 baseline.
        $new = ComponentLifecycle::find($replacement->new_lifecycle_id);
        $this->assertSame('active', $new->status);
        $this->assertSame(15000, $new->baseline_expected_clicks_snapshot);
    }

    public function test_manual_component_override_is_not_affected_by_a_profile_slot_that_happens_to_share_its_slot_code(): void
    {
        $f = $this->f();
        // A machine-specific manual component: no profile_slot_id, user-owned baseline.
        $manualComponent = ComponentCatalog::create(['code' => 'TONER_C_OVERRIDE', 'name' => 'Toner Cyan (override)']);
        $manualMc = MachineComponent::create(['account_id' => $f['a']->id, 'machine_id' => $f['m']->id, 'component_id' => $manualComponent->id, 'slot_code' => 'TONER_C_MANUAL', 'source_type' => 'manual', 'status' => 'configured', 'active_key' => 'active', 'tracking_method' => 'counter_based', 'baseline_expected_clicks' => 20000]);

        $lifecycle = app(ComponentConfigurationService::class)->initialize($manualMc, ['started_at' => now()]);
        $this->assertSame(20000, $lifecycle->baseline_expected_clicks_snapshot);

        // Editing the (unrelated) profile slot must never affect the manual component's own baseline.
        $f['slot']->update(['baseline_expected_clicks' => 15000]);
        $lifecycle->refresh();
        $this->assertSame(20000, $lifecycle->baseline_expected_clicks_snapshot);
    }

    public function test_resolve_effective_baseline_falls_back_to_machine_component_value_when_profile_slot_is_no_longer_active(): void
    {
        $f = $this->f();
        $f['slot']->update(['is_active' => false]);

        $baseline = app(ComponentConfigurationService::class)->resolveEffectiveBaseline($f['mc']->fresh());

        $this->assertSame(16000, $baseline, 'falls back to the machine component snapshot rather than fabricating a value from an inactive slot');
    }
}
