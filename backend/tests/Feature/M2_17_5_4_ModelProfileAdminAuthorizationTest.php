<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\ComponentCatalog;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\ModelProfile;
use App\Models\ModelProfileSlot;
use App\Models\PlatformUserPrivilege;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M2.17.5.4 Part B: a real Production Account Admin, editing an existing Model
 * Profile Slot's baseline_expected_clicks, got "Your current workspace role is
 * not allowed to assign this component." Root cause: NOT an authorization bug -
 * ComponentsController::authorizeCatalogScope() correctly requires Platform
 * Superuser when a catalog-shaped resource's account_id is null. The real
 * problem is Production DATA: the Machine Model ("Bizhub Press C1070") is
 * account-owned, but its "Legacy Approved C1070 Profile" - created during the
 * Supabase-to-Laravel migration - was left with account_id NULL, making it look
 * platform-global when it should have matched its own model's account. This
 * suite proves: (1) the CURRENT authorization matrix is already correct and
 * must stay that way (a genuinely null-scoped profile does require Superuser -
 * that is not the bug), (2) an Account Admin CAN already manage a correctly
 * account-scoped profile (already covered by M2_17_5_2, re-asserted here against
 * the exact mission scenario), (3) Operator/Technician remain excluded, (4) the
 * B4 round-trip regression with the mission's exact values still holds.
 */
class M2_17_5_4_ModelProfileAdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function graph(): array
    {
        $a = Account::create(['code' => 'B4', 'name' => 'Model Profile Auth']);
        $admin = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $a->id, 'user_id' => $admin->id, 'role' => 'admin', 'status' => 'active', 'accepted_at' => now()]);
        $operator = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $a->id, 'user_id' => $operator->id, 'role' => 'operator', 'status' => 'active', 'accepted_at' => now()]);
        $technician = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $a->id, 'user_id' => $technician->id, 'role' => 'technician', 'status' => 'active', 'accepted_at' => now()]);
        $superuser = User::factory()->create(['status' => 'active']);
        PlatformUserPrivilege::create(['user_id' => $superuser->id, 'role' => 'superuser', 'is_active' => true]);
        $km = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $drum = ComponentCatalog::create(['code' => 'DRUM', 'name' => 'Drum Unit']);
        // Reproduces the exact real-world mismatch: an account-owned Machine
        // Model whose Model Profile was left account_id=null by the legacy import.
        $accountOwnedModel = MachineModel::create(['account_id' => $a->id, 'manufacturer_id' => $km->id, 'model_code' => 'C1070', 'name' => 'Bizhub Press C1070']);
        $legacyNullProfile = ModelProfile::create(['account_id' => null, 'machine_model_id' => $accountOwnedModel->id, 'name' => 'Legacy Approved C1070 Profile']);
        $legacySlot = ModelProfileSlot::create(['profile_id' => $legacyNullProfile->id, 'component_id' => $drum->id, 'slot_code' => 'TONER_C', 'tracking_method' => 'counter_based', 'baseline_expected_clicks' => 16000]);
        // The correctly-reconciled counterpart: same model, but with account_id
        // matching its own machine model, as storeProfile() already produces for
        // every profile created through the normal API today.
        $correctProfile = ModelProfile::create(['account_id' => $a->id, 'machine_model_id' => $accountOwnedModel->id, 'name' => 'Reconciled C1070 Profile']);
        $correctSlot = ModelProfileSlot::create(['profile_id' => $correctProfile->id, 'component_id' => $drum->id, 'slot_code' => 'TONER_K', 'tracking_method' => 'counter_based', 'baseline_expected_clicks' => 25000]);

        return compact('a', 'admin', 'operator', 'technician', 'superuser', 'accountOwnedModel', 'legacyNullProfile', 'legacySlot', 'correctProfile', 'correctSlot');
    }

    public function test_account_admin_is_correctly_rejected_on_a_genuinely_null_scoped_legacy_profile(): void
    {
        $f = $this->graph();
        // This is NOT the bug - a null-scoped catalog resource requiring
        // Superuser is the existing, intentional, correct rule.
        $this->actingAs($f['admin'])->putJson('/api/v1/model-profile-slots/'.$f['legacySlot']->id, ['baseline_expected_clicks' => 15000])
            ->assertForbidden();
    }

    public function test_platform_superuser_can_edit_the_null_scoped_legacy_profile(): void
    {
        $f = $this->graph();
        $this->actingAs($f['superuser'])->putJson('/api/v1/model-profile-slots/'.$f['legacySlot']->id, ['baseline_expected_clicks' => 15000])
            ->assertOk()->assertJsonPath('data.baseline_expected_clicks', 15000);
    }

    public function test_account_admin_can_edit_the_correctly_account_scoped_profile_matching_the_real_mission_scenario(): void
    {
        $f = $this->graph();
        $this->actingAs($f['admin'])->putJson('/api/v1/model-profile-slots/'.$f['correctSlot']->id, ['baseline_expected_clicks' => 15000])
            ->assertOk()->assertJsonPath('data.baseline_expected_clicks', 15000);
    }

    public function test_operator_cannot_manage_model_profile_configuration(): void
    {
        $f = $this->graph();
        $this->actingAs($f['operator'])->putJson('/api/v1/model-profile-slots/'.$f['correctSlot']->id, ['baseline_expected_clicks' => 15000])
            ->assertForbidden();
    }

    public function test_technician_cannot_manage_model_profile_configuration(): void
    {
        $f = $this->graph();
        $this->actingAs($f['technician'])->putJson('/api/v1/model-profile-slots/'.$f['correctSlot']->id, ['baseline_expected_clicks' => 15000])
            ->assertForbidden();
    }

    public function test_reconciling_account_id_to_match_the_machine_model_makes_admin_editing_work(): void
    {
        $f = $this->graph();
        // Prove the narrow, evidence-backed fix: correcting the legacy profile's
        // account_id (not touching any authorization code) is sufficient.
        $f['legacyNullProfile']->update(['account_id' => $f['a']->id]);
        $this->actingAs($f['admin'])->putJson('/api/v1/model-profile-slots/'.$f['legacySlot']->id, ['baseline_expected_clicks' => 15000])
            ->assertOk()->assertJsonPath('data.baseline_expected_clicks', 15000);
    }

    // Part B4: exact mission round-trip regression.
    public function test_b4_exact_round_trip_regression(): void
    {
        $f = $this->graph();
        $this->actingAs($f['admin'])->putJson('/api/v1/model-profile-slots/'.$f['correctSlot']->id, [
            'baseline_expected_clicks' => 15000, 'adaptive_enabled' => true,
            'healthy_threshold_percent' => 30, 'watch_threshold_percent' => 15, 'warning_threshold_percent' => 5, 'critical_threshold_percent' => 0,
        ])->assertOk();

        $fresh = $f['correctSlot']->fresh();
        $this->assertSame(15000, $fresh->baseline_expected_clicks);
        $this->assertTrue($fresh->adaptive_enabled);
        $this->assertEquals(30, $fresh->healthy_threshold_percent);
        $this->assertEquals(15, $fresh->watch_threshold_percent);
        $this->assertEquals(5, $fresh->warning_threshold_percent);
        $this->assertEquals(0, $fresh->critical_threshold_percent);

        // Partial edit afterward must not disturb the untouched fields.
        $this->actingAs($f['admin'])->putJson('/api/v1/model-profile-slots/'.$f['correctSlot']->id, ['notes' => 'reviewed'])->assertOk();
        $afterPartial = $f['correctSlot']->fresh();
        $this->assertSame(15000, $afterPartial->baseline_expected_clicks);
        $this->assertTrue($afterPartial->adaptive_enabled);
        $this->assertEquals(30, $afterPartial->healthy_threshold_percent);
        $this->assertEquals(0, $afterPartial->critical_threshold_percent);
        $this->assertSame('reviewed', $afterPartial->notes);
    }
}
