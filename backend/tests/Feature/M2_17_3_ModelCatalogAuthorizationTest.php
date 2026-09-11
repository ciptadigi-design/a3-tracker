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
 * M2.17.3 Scope D: "Assign to model" returned Forbidden for a normal Account
 * Admin because every Component Catalog / Machine Model / Model Profile
 * mutation endpoint was gated to platform Superuser regardless of whether the
 * record was account-owned (account_id set) or platform-global (null). These
 * tests pin the corrected matrix - Superuser/Owner/Admin allowed within an
 * account-owned scope, Technician/Operator denied, cross-account forgery
 * denied - and the write-path fix (assigning a component now actually
 * persists a queryable Model Profile Slot instead of silently dropping it).
 */
class M2_17_3_ModelCatalogAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $code = 'CMP'): Account
    {
        return Account::create(['code' => $code, 'name' => $code.' Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
    }

    private function member(Account $account, string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active', 'accepted_at' => now()]);

        return $user;
    }

    private function superuser(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        PlatformUserPrivilege::create(['user_id' => $user->id, 'role' => 'superuser', 'is_active' => true]);

        return $user;
    }

    private function accountOwnedModel(Account $account): array
    {
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $model = MachineModel::create(['account_id' => $account->id, 'manufacturer_id' => $manufacturer->id, 'model_code' => 'c1070-'.$account->id, 'name' => 'C1070']);
        $component = ComponentCatalog::create(['code' => 'drum-'.$account->id, 'name' => 'Drum']);

        return compact('model', 'component');
    }

    private function assignPayload(string $componentId, string $slot = 'DRUM-C'): array
    {
        return ['component_id' => $componentId, 'slot_code' => $slot, 'display_order' => 0, 'tracking_method' => 'counter_based', 'baseline_expected_clicks' => 30000];
    }

    public function test_superuser_can_assign_component_to_an_account_owned_model(): void
    {
        $account = $this->account();
        ['model' => $model, 'component' => $component] = $this->accountOwnedModel($account);
        $superuser = $this->superuser();

        $this->actingAs($superuser)->postJson("/api/v1/machine-models/{$model->id}/profiles", $this->assignPayload($component->id))->assertCreated();
    }

    public function test_account_owner_can_assign_component_to_their_own_account_model(): void
    {
        $account = $this->account();
        ['model' => $model, 'component' => $component] = $this->accountOwnedModel($account);
        $owner = $this->member($account, 'owner');

        $this->actingAs($owner)->postJson("/api/v1/machine-models/{$model->id}/profiles", $this->assignPayload($component->id))->assertCreated();
    }

    public function test_account_admin_can_assign_component_to_their_own_account_model(): void
    {
        $account = $this->account();
        ['model' => $model, 'component' => $component] = $this->accountOwnedModel($account);
        $admin = $this->member($account, 'admin');

        $response = $this->actingAs($admin)->postJson("/api/v1/machine-models/{$model->id}/profiles", $this->assignPayload($component->id))->assertCreated();

        $slotId = $response->json('data.id');
        $slot = ModelProfileSlot::findOrFail($slotId);
        $this->assertSame((string) $component->id, (string) $slot->component_id);
        $this->assertSame('DRUM-C', $slot->slot_code);
        $this->assertSame(30000, $slot->baseline_expected_clicks);
        $this->assertSame('counter_based', $slot->tracking_method);
        $this->assertNotNull($slot->profile_id);
        $this->assertSame((string) $account->id, (string) $slot->profile->account_id);
    }

    public function test_technician_is_forbidden_from_assigning_a_component(): void
    {
        $account = $this->account();
        ['model' => $model, 'component' => $component] = $this->accountOwnedModel($account);
        $technician = $this->member($account, 'technician');

        $this->actingAs($technician)->postJson("/api/v1/machine-models/{$model->id}/profiles", $this->assignPayload($component->id))->assertForbidden();
    }

    public function test_operator_is_forbidden_from_assigning_a_component(): void
    {
        $account = $this->account();
        ['model' => $model, 'component' => $component] = $this->accountOwnedModel($account);
        $operator = $this->member($account, 'operator');

        $this->actingAs($operator)->postJson("/api/v1/machine-models/{$model->id}/profiles", $this->assignPayload($component->id))->assertForbidden();
    }

    public function test_admin_of_another_account_cannot_assign_a_component_to_a_foreign_account_model(): void
    {
        $account = $this->account('CMP');
        ['model' => $model, 'component' => $component] = $this->accountOwnedModel($account);
        $foreignAccount = $this->account('OTH');
        $foreignAdmin = $this->member($foreignAccount, 'admin');

        $this->actingAs($foreignAdmin)->postJson("/api/v1/machine-models/{$model->id}/profiles", $this->assignPayload($component->id))->assertForbidden();
    }

    public function test_account_admin_cannot_manage_a_platform_global_model(): void
    {
        $account = $this->account();
        $admin = $this->member($account, 'admin');
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $globalModel = MachineModel::create(['account_id' => null, 'manufacturer_id' => $manufacturer->id, 'model_code' => 'global-c1070', 'name' => 'C1070']);
        $component = ComponentCatalog::create(['code' => 'global-drum', 'name' => 'Drum']);

        $this->actingAs($admin)->postJson("/api/v1/machine-models/{$globalModel->id}/profiles", $this->assignPayload($component->id))->assertForbidden();
    }

    public function test_superuser_can_manage_a_platform_global_model(): void
    {
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $globalModel = MachineModel::create(['account_id' => null, 'manufacturer_id' => $manufacturer->id, 'model_code' => 'global-c1070-2', 'name' => 'C1070']);
        $component = ComponentCatalog::create(['code' => 'global-drum-2', 'name' => 'Drum']);
        $superuser = $this->superuser();

        $this->actingAs($superuser)->postJson("/api/v1/machine-models/{$globalModel->id}/profiles", $this->assignPayload($component->id))->assertCreated();
    }

    public function test_editing_an_assigned_slot_updates_it_in_place_without_duplicating(): void
    {
        $account = $this->account();
        ['model' => $model, 'component' => $component] = $this->accountOwnedModel($account);
        $admin = $this->member($account, 'admin');

        $created = $this->actingAs($admin)->postJson("/api/v1/machine-models/{$model->id}/profiles", $this->assignPayload($component->id))->assertCreated();
        $slotId = $created->json('data.id');

        $this->actingAs($admin)->putJson("/api/v1/model-profile-slots/{$slotId}", ['display_order' => 2, 'baseline_expected_clicks' => 45000])->assertOk();

        $this->assertSame(1, ModelProfileSlot::where('component_id', $component->id)->count());
        $slot = ModelProfileSlot::findOrFail($slotId);
        $this->assertSame(45000, $slot->baseline_expected_clicks);
        $this->assertSame(2, $slot->display_order);
    }

    public function test_another_accounts_admin_cannot_edit_a_foreign_slot(): void
    {
        $account = $this->account('CMP');
        ['model' => $model, 'component' => $component] = $this->accountOwnedModel($account);
        $admin = $this->member($account, 'admin');
        $created = $this->actingAs($admin)->postJson("/api/v1/machine-models/{$model->id}/profiles", $this->assignPayload($component->id))->assertCreated();
        $slotId = $created->json('data.id');

        $foreignAccount = $this->account('OTH');
        $foreignAdmin = $this->member($foreignAccount, 'admin');
        $this->actingAs($foreignAdmin)->putJson("/api/v1/model-profile-slots/{$slotId}", ['display_order' => 9])->assertForbidden();
    }

    public function test_archiving_a_slot_marks_it_inactive(): void
    {
        $account = $this->account();
        ['model' => $model, 'component' => $component] = $this->accountOwnedModel($account);
        $admin = $this->member($account, 'admin');
        $created = $this->actingAs($admin)->postJson("/api/v1/machine-models/{$model->id}/profiles", $this->assignPayload($component->id))->assertCreated();
        $slotId = $created->json('data.id');

        $this->actingAs($admin)->patchJson("/api/v1/model-profile-slots/{$slotId}/status", ['is_active' => false])->assertOk();

        $this->assertFalse(ModelProfileSlot::findOrFail($slotId)->is_active);
    }

    public function test_profiles_listing_does_not_leak_another_accounts_custom_profile(): void
    {
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $sharedModel = MachineModel::create(['account_id' => null, 'manufacturer_id' => $manufacturer->id, 'model_code' => 'shared-c1070', 'name' => 'C1070']);

        $accountA = $this->account('AAA');
        $profileA = ModelProfile::create(['account_id' => $accountA->id, 'machine_model_id' => $sharedModel->id, 'name' => 'Account A profile']);
        $componentA = ComponentCatalog::create(['code' => 'a-drum', 'name' => 'Drum A']);
        ModelProfileSlot::create(['profile_id' => $profileA->id, 'component_id' => $componentA->id, 'slot_code' => 'DRUM-A']);

        $accountB = $this->account('BBB');
        $memberB = $this->member($accountB, 'admin');

        $response = $this->actingAs($memberB)->getJson("/api/v1/machine-models/{$sharedModel->id}/profiles")->assertOk();
        $this->assertSame([], $response->json('data'));
    }
}
