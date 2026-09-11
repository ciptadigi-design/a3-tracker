<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Branch;
use App\Models\ComponentCatalog;
use App\Models\Machine;
use App\Models\MachineComponent;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\ModelProfile;
use App\Models\ModelProfileSlot;
use App\Models\PlatformUserPrivilege;
use App\Models\User;
use App\Services\ComponentConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * M2.17.5.2 - ADDITIONAL SCOPE: Component Catalog / Model Profile integrity.
 *
 * Part A: Add Component's Manufacturer dropdown was empty even though GET
 * /manufacturers already correctly returned global + account-scoped active
 * manufacturers. Root cause was three-layered: loadComponentFoundation() never
 * fetched them, component_catalogs had no manufacturer_id column at all, and
 * saveComponent() forwarded raw camelCase draft state that Laravel's validate()
 * silently dropped. Manufacturer is metadata only - it must never imply
 * machine-model compatibility, and "Any manufacturer" must persist as a real NULL.
 *
 * Part B: Edit Profile's threshold/adaptive-foundation fields round-tripped
 * against a ModelProfileSlot object that had no backing columns for them at all -
 * every save silently dropped the extra keys, then reopening re-derived
 * undefined/blank. model_profile_slots is now the template source of truth for
 * tracking_method/baseline_expected_clicks/thresholds, snapshotted onto
 * machine_components at sync()/reconcileManual() time (never re-synchronized
 * afterwards).
 *
 * Part C/D: only counter_based tracking is operationally supported (addManual()
 * already hard-rejects anything else) - Model Profile Slot create/update must
 * reject the same forged unsupported methods, while historical rows that
 * predate this constraint must remain readable untouched.
 */
class M2_17_5_2_ComponentCatalogAndModelProfileIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function graph(): array
    {
        $a = Account::create(['code' => 'CCM', 'name' => 'Catalog Model']);
        $other = Account::create(['code' => 'CCM2', 'name' => 'Other Account']);
        $branch = Branch::create(['account_id' => $a->id, 'code' => 'MAIN', 'name' => 'Main']);
        $admin = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $a->id, 'user_id' => $admin->id, 'role' => 'admin', 'status' => 'active', 'accepted_at' => now()]);
        $otherAdmin = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $other->id, 'user_id' => $otherAdmin->id, 'role' => 'admin', 'status' => 'active', 'accepted_at' => now()]);
        $superuser = User::factory()->create(['status' => 'active']);
        PlatformUserPrivilege::create(['user_id' => $superuser->id, 'role' => 'superuser', 'is_active' => true]);
        $km = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $xerox = Manufacturer::create(['code' => 'XRX', 'name' => 'Xerox']);
        $drum = ComponentCatalog::create(['account_id' => $a->id, 'code' => 'DRUM', 'name' => 'Drum Unit']);
        $model = MachineModel::create(['account_id' => $a->id, 'manufacturer_id' => $km->id, 'model_code' => 'C1070', 'name' => 'C1070']);
        $profile = ModelProfile::create(['account_id' => $a->id, 'machine_model_id' => $model->id, 'name' => 'C1070 profile']);
        $machine = Machine::create(['account_id' => $a->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'M1', 'display_name' => 'M1']);

        return compact('a', 'other', 'admin', 'otherAdmin', 'superuser', 'km', 'xerox', 'drum', 'model', 'profile', 'machine');
    }

    // --- Part A: Manufacturer -------------------------------------------------

    public function test_manufacturer_dropdown_endpoint_returns_active_globals_for_an_account_admin(): void
    {
        $f = $this->graph();
        $res = $this->actingAs($f['admin'])->getJson('/api/v1/manufacturers?account_id='.$f['a']->id)->assertOk();
        $names = collect($res->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Konica Minolta'));
        $this->assertTrue($names->contains('Xerox'));
    }

    public function test_account_admin_can_read_global_manufacturer_master_without_platform_superuser(): void
    {
        $f = $this->graph();
        $this->actingAs($f['admin'])->getJson('/api/v1/manufacturers?account_id='.$f['a']->id)->assertOk();
    }

    public function test_account_admin_cannot_mutate_platform_manufacturer_without_superuser(): void
    {
        $f = $this->graph();
        $this->actingAs($f['admin'])->postJson('/api/v1/manufacturers', ['code' => 'NEW', 'name' => 'New Manufacturer'])->assertForbidden();
    }

    public function test_platform_superuser_can_create_manufacturer(): void
    {
        $f = $this->graph();
        $this->actingAs($f['superuser'])->postJson('/api/v1/manufacturers', ['code' => 'NEW', 'name' => 'New Manufacturer'])->assertCreated();
    }

    public function test_selecting_manufacturer_on_a_component_creates_no_model_compatibility_side_effect(): void
    {
        $f = $this->graph();
        $before = ModelProfileSlot::count();
        $this->actingAs($f['admin'])->putJson('/api/v1/components/'.$f['drum']->id, [
            'manufacturer_id' => $f['km']->id, 'code' => 'DRUM', 'name' => 'Drum Unit', 'category' => null,
        ])->assertOk()->assertJsonPath('data.manufacturer_id', (string) $f['km']->id);
        $this->assertSame($before, ModelProfileSlot::count());
        $this->assertSame(0, MachineComponent::count());
    }

    public function test_any_manufacturer_persists_as_canonical_null_not_a_default_row(): void
    {
        $f = $this->graph();
        $this->actingAs($f['admin'])->putJson('/api/v1/components/'.$f['drum']->id, [
            'manufacturer_id' => null, 'code' => 'DRUM', 'name' => 'Drum Unit', 'category' => null,
        ])->assertOk()->assertJsonPath('data.manufacturer_id', null);
        $this->assertNull($f['drum']->fresh()->manufacturer_id);
    }

    public function test_forged_manufacturer_id_that_does_not_exist_is_rejected(): void
    {
        $f = $this->graph();
        $this->actingAs($f['admin'])->putJson('/api/v1/components/'.$f['drum']->id, [
            'manufacturer_id' => (string) Str::uuid(), 'code' => 'DRUM', 'name' => 'Drum Unit',
        ])->assertStatus(422);
    }

    // --- Part B: Model Profile threshold / adaptive foundation round trip -----

    public function test_profile_slot_thresholds_and_adaptive_foundation_persist_on_create(): void
    {
        $f = $this->graph();
        $res = $this->actingAs($f['admin'])->postJson('/api/v1/machine-models/'.$f['model']->id.'/profiles', [
            'component_id' => $f['drum']->id, 'slot_code' => 'DRUM-C', 'tracking_method' => 'counter_based',
            'baseline_expected_clicks' => 15989, 'adaptive_enabled' => true,
            'healthy_threshold_percent' => 30, 'watch_threshold_percent' => 15, 'warning_threshold_percent' => 5, 'critical_threshold_percent' => 0,
        ])->assertCreated();
        $slotId = $res->json('data.id');
        $slot = ModelProfileSlot::findOrFail($slotId);
        $this->assertSame(15989, $slot->baseline_expected_clicks);
        $this->assertTrue($slot->adaptive_enabled);
        $this->assertEquals(30, $slot->healthy_threshold_percent);
        $this->assertEquals(15, $slot->watch_threshold_percent);
        $this->assertEquals(5, $slot->warning_threshold_percent);
        $this->assertEquals(0, $slot->critical_threshold_percent);
    }

    public function test_profile_slot_thresholds_and_adaptive_foundation_hydrate_on_reload(): void
    {
        $f = $this->graph();
        $slot = ModelProfileSlot::create(['profile_id' => $f['profile']->id, 'component_id' => $f['drum']->id, 'slot_code' => 'DRUM-C', 'baseline_expected_clicks' => 15989, 'adaptive_enabled' => true, 'healthy_threshold_percent' => 30, 'watch_threshold_percent' => 15, 'warning_threshold_percent' => 5, 'critical_threshold_percent' => 0]);
        $res = $this->actingAs($f['admin'])->getJson('/api/v1/machine-models/'.$f['model']->id.'/profiles')->assertOk();
        $row = collect($res->json('data.0.slots'))->firstWhere('id', $slot->id);
        $this->assertSame(15989, $row['baseline_expected_clicks']);
        $this->assertTrue((bool) $row['adaptive_enabled']);
        $this->assertEquals(30, $row['healthy_threshold_percent']);
        $this->assertEquals(15, $row['watch_threshold_percent']);
        $this->assertEquals(5, $row['warning_threshold_percent']);
        $this->assertEquals(0, $row['critical_threshold_percent']);
    }

    public function test_partial_profile_edit_of_notes_leaves_thresholds_and_adaptive_foundation_unchanged(): void
    {
        $f = $this->graph();
        $slot = ModelProfileSlot::create(['profile_id' => $f['profile']->id, 'component_id' => $f['drum']->id, 'slot_code' => 'DRUM-C', 'baseline_expected_clicks' => 15989, 'adaptive_enabled' => true, 'healthy_threshold_percent' => 30, 'watch_threshold_percent' => 15, 'warning_threshold_percent' => 5, 'critical_threshold_percent' => 0]);
        $this->actingAs($f['admin'])->putJson('/api/v1/model-profile-slots/'.$slot->id, ['notes' => 'inspected quarterly'])->assertOk();
        $fresh = $slot->fresh();
        $this->assertSame(15989, $fresh->baseline_expected_clicks);
        $this->assertTrue($fresh->adaptive_enabled);
        $this->assertEquals(30, $fresh->healthy_threshold_percent);
        $this->assertEquals(0, $fresh->critical_threshold_percent);
        $this->assertSame('inspected quarterly', $fresh->notes);
    }

    public function test_invalid_threshold_ordering_is_rejected_on_update(): void
    {
        $f = $this->graph();
        $slot = ModelProfileSlot::create(['profile_id' => $f['profile']->id, 'component_id' => $f['drum']->id, 'slot_code' => 'DRUM-C']);
        $this->actingAs($f['admin'])->putJson('/api/v1/model-profile-slots/'.$slot->id, [
            'healthy_threshold_percent' => 10, 'watch_threshold_percent' => 15, 'warning_threshold_percent' => 5, 'critical_threshold_percent' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors('critical_threshold_percent');
    }

    public function test_invalid_threshold_ordering_is_rejected_on_create(): void
    {
        $f = $this->graph();
        $this->actingAs($f['admin'])->postJson('/api/v1/machine-models/'.$f['model']->id.'/profiles', [
            'component_id' => $f['drum']->id, 'slot_code' => 'DRUM-M',
            'healthy_threshold_percent' => 5, 'watch_threshold_percent' => 15, 'warning_threshold_percent' => 30, 'critical_threshold_percent' => 0,
        ])->assertStatus(422);
    }

    public function test_cross_account_model_profile_slot_mutation_is_forbidden(): void
    {
        $f = $this->graph();
        $slot = ModelProfileSlot::create(['profile_id' => $f['profile']->id, 'component_id' => $f['drum']->id, 'slot_code' => 'DRUM-C']);
        $this->actingAs($f['otherAdmin'])->putJson('/api/v1/model-profile-slots/'.$slot->id, ['notes' => 'hostile edit'])->assertForbidden();
        $this->assertNull($slot->fresh()->notes);
    }

    // --- Part C: unsupported tracking methods ----------------------------------

    public function test_unsupported_tracking_method_cannot_be_newly_persisted_on_create(): void
    {
        $f = $this->graph();
        $this->actingAs($f['admin'])->postJson('/api/v1/machine-models/'.$f['model']->id.'/profiles', [
            'component_id' => $f['drum']->id, 'slot_code' => 'DRUM-C', 'tracking_method' => 'consumption_based',
        ])->assertStatus(422);
        $this->assertSame(0, ModelProfileSlot::count());
    }

    public function test_forged_unsupported_tracking_method_is_rejected_on_update(): void
    {
        $f = $this->graph();
        $slot = ModelProfileSlot::create(['profile_id' => $f['profile']->id, 'component_id' => $f['drum']->id, 'slot_code' => 'DRUM-C', 'tracking_method' => 'counter_based']);
        $this->actingAs($f['admin'])->putJson('/api/v1/model-profile-slots/'.$slot->id, ['tracking_method' => 'inspection_based'])->assertStatus(422);
        $this->assertSame('counter_based', $slot->fresh()->tracking_method);
    }

    public function test_forged_unsupported_tracking_method_is_rejected_on_manual_machine_component_add(): void
    {
        $f = $this->graph();
        $this->actingAs($f['admin'])->postJson('/api/v1/machines/'.$f['machine']->id.'/components/manual', [
            'component_id' => $f['drum']->id, 'slot_code' => 'EXTRA-01', 'tracking_method' => 'consumption_based', 'baseline_expected_clicks' => 1000,
        ])->assertStatus(422);
    }

    public function test_historical_unsupported_tracking_method_remains_readable(): void
    {
        $f = $this->graph();
        // Simulate a legacy row written before this constraint existed - never
        // rewritten automatically, only guarded against on new writes going forward.
        $legacySlotId = (string) Str::uuid();
        DB::table('model_profile_slots')->insert([
            'id' => $legacySlotId, 'profile_id' => $f['profile']->id, 'component_id' => $f['drum']->id,
            'slot_code' => 'LEGACY-CONSUMPTION', 'display_order' => 0, 'tracking_method' => 'consumption_based',
            'healthy_threshold_percent' => 30, 'watch_threshold_percent' => 15, 'warning_threshold_percent' => 5, 'critical_threshold_percent' => 0,
            'adaptive_enabled' => 1, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $res = $this->actingAs($f['admin'])->getJson('/api/v1/machine-models/'.$f['model']->id.'/profiles')->assertOk();
        $row = collect($res->json('data.0.slots'))->firstWhere('id', $legacySlotId);
        $this->assertNotNull($row, 'legacy unsupported-tracking-method slot must still be readable');
        $this->assertSame('consumption_based', $row['tracking_method']);
    }

    // --- Part D: source-of-truth / inheritance precedence ----------------------

    public function test_sync_snapshots_model_profile_slot_thresholds_onto_new_machine_component(): void
    {
        $f = $this->graph();
        ModelProfileSlot::create(['profile_id' => $f['profile']->id, 'component_id' => $f['drum']->id, 'slot_code' => 'DRUM-C', 'baseline_expected_clicks' => 15989, 'healthy_threshold_percent' => 40, 'watch_threshold_percent' => 20, 'warning_threshold_percent' => 10, 'critical_threshold_percent' => 2]);
        app(ComponentConfigurationService::class)->sync($f['machine']);
        $mc = MachineComponent::where('machine_id', $f['machine']->id)->where('slot_code', 'DRUM-C')->firstOrFail();
        $this->assertSame(15989, $mc->baseline_expected_clicks);
        $this->assertEquals(40, $mc->healthy_threshold_percent);
        $this->assertEquals(20, $mc->watch_threshold_percent);
        $this->assertEquals(10, $mc->warning_threshold_percent);
        $this->assertEquals(2, $mc->critical_threshold_percent);
    }

    public function test_sync_is_a_one_time_snapshot_and_does_not_live_resync_after_slot_edit(): void
    {
        $f = $this->graph();
        $slot = ModelProfileSlot::create(['profile_id' => $f['profile']->id, 'component_id' => $f['drum']->id, 'slot_code' => 'DRUM-C', 'healthy_threshold_percent' => 40, 'watch_threshold_percent' => 20, 'warning_threshold_percent' => 10, 'critical_threshold_percent' => 2]);
        app(ComponentConfigurationService::class)->sync($f['machine']);
        $mc = MachineComponent::where('machine_id', $f['machine']->id)->where('slot_code', 'DRUM-C')->firstOrFail();
        $slot->update(['healthy_threshold_percent' => 50]);
        $this->assertEquals(40, $mc->fresh()->healthy_threshold_percent, 'machine_components must not silently re-synchronize after the template changes');
    }
}
