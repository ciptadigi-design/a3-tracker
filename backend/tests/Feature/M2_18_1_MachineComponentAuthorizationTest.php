<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Branch;
use App\Models\ComponentCatalog;
use App\Models\Machine;
use App\Models\MachineComponent;
use App\Models\MachineComponentExclusion;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\ModelProfile;
use App\Models\ModelProfileSlot;
use App\Models\PlatformUserPrivilege;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M2.18.1 (closing M2.18 audit BLOCKER B1): ComponentsController's 8
 * machine-component mutation/read endpoints (sync, add, exclude,
 * clearExclusion, initialize, reconcile, reconciliationCandidate, remove)
 * previously called findOrFail() then acted immediately with NO
 * authorization check at all - any authenticated active user, any account,
 * any role, could mutate any other account's machine components.
 *
 * Two authorization tiers apply, matching established sibling patterns
 * already in the codebase (not invented for this fix):
 *  - CONFIGURATION tier (sync/add/exclude/clearExclusion/reconcile/remove):
 *    owner/admin/platform-superuser only, via AccountAccessResolver::
 *    canManageOperational() - the same check storeMachine()/updateMachine()/
 *    setMachineStatus() already use for machine-level configuration.
 *  - OPERATIONAL tier (initialize - recording a physical install event,
 *    the same class of action as InventoryController::replace()): any
 *    branch-scoped role, via MachineAccessResolver::canAccess($user,
 *    $machine, true) - the same check replace() and createCounter() use.
 *  - READ tier (reconciliationCandidate - verified read-only, no writes
 *    anywhere in ComponentConfigurationService::reconciliationCandidate()):
 *    any branch-scoped role, via MachineAccessResolver::canAccess($user,
 *    $machine) (default write=false) - the same check machineComponents()
 *    already uses.
 */
class M2_18_1_MachineComponentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $home = Account::create(['code' => 'HOME', 'name' => 'Home Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $foreign = Account::create(['code' => 'FRGN', 'name' => 'Foreign Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $branch = Branch::create(['account_id' => $home->id, 'code' => 'MAIN', 'name' => 'Main', 'is_active' => true]);
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'A3', 'name' => 'A3']);
        $catalog = ComponentCatalog::create(['code' => 'TONER_C', 'name' => 'Toner Cyan']);
        $profile = ModelProfile::create(['machine_model_id' => $model->id, 'account_id' => $home->id, 'name' => 'Home profile', 'is_active' => true]);
        $slot = ModelProfileSlot::create(['profile_id' => $profile->id, 'component_id' => $catalog->id, 'slot_code' => 'TONER_C', 'baseline_expected_clicks' => 16000, 'is_active' => true]);
        $machine = Machine::create(['account_id' => $home->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'HOME-A3-01', 'display_name' => 'Home A3', 'status' => 'active']);
        $mc = MachineComponent::create(['account_id' => $home->id, 'machine_id' => $machine->id, 'component_id' => $catalog->id, 'profile_slot_id' => $slot->id, 'slot_code' => 'TONER_C', 'source_type' => 'inherited', 'status' => 'configured', 'active_key' => 'active', 'baseline_expected_clicks' => 16000]);
        $exclusion = MachineComponentExclusion::create(['account_id' => $home->id, 'machine_id' => $machine->id, 'profile_slot_id' => $slot->id, 'slot_code' => 'TONER_C', 'reason' => 'seed']);

        return compact('home', 'foreign', 'branch', 'manufacturer', 'model', 'catalog', 'profile', 'slot', 'machine', 'mc', 'exclusion');
    }

    private function member(Account $account, string $role, ?Branch $branch = null): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active', 'accepted_at' => now()]);
        if ($branch) {
            AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $branch->id, 'is_active' => true]);
        }

        return $user;
    }

    private function superuser(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        PlatformUserPrivilege::create(['user_id' => $user->id, 'role' => 'superuser', 'is_active' => true]);

        return $user;
    }

    /** One [method, urlBuilder, payload] entry per CONFIGURATION-tier endpoint, using the fixture's own machine/component so a 403 can only mean authorization, not a 404/422 on unrelated grounds. */
    private function configurationTierRequests(array $f): array
    {
        return [
            'sync' => ['post', "/api/v1/machines/{$f['machine']->id}/components/sync", []],
            'add' => ['post', "/api/v1/machines/{$f['machine']->id}/components/manual", ['component_id' => $f['catalog']->id, 'slot_code' => 'TONER_M', 'tracking_method' => 'counter_based', 'baseline_expected_clicks' => 10000]],
            'exclude' => ['post', "/api/v1/machine-components/{$f['mc']->id}/exclude", ['reason' => 'test']],
            'clearExclusion' => ['post', "/api/v1/component-exclusions/{$f['exclusion']->id}/clear", []],
            'remove' => ['patch', "/api/v1/machine-components/{$f['mc']->id}", []],
        ];
    }

    private function hit(array $f, string $key, User $as)
    {
        [$method, $url, $payload] = $this->configurationTierRequests($f)[$key];

        return $this->actingAs($as)->json($method, $url, $payload);
    }

    // --- A. CROSS-ACCOUNT: an owner of a completely unrelated account must be rejected on every configuration-tier endpoint ---

    public function test_cross_account_owner_is_forbidden_on_every_configuration_tier_endpoint(): void
    {
        $f = $this->fixture();
        $attacker = $this->member($f['foreign'], 'owner');

        foreach (array_keys($this->configurationTierRequests($f)) as $key) {
            $this->hit($f, $key, $attacker)->assertForbidden();
        }
    }

    public function test_cross_account_owner_is_forbidden_on_reconcile(): void
    {
        $f = $this->fixture();
        $attacker = $this->member($f['foreign'], 'owner');
        // A second slot matching what will become mc2's identity exactly, so
        // reconcileManual()'s own business-rule validation would pass if
        // authorization were (wrongly) granted - proving a 403 here is
        // attributable to authorization, not an unrelated conflict.
        $slot2 = ModelProfileSlot::create(['profile_id' => $f['profile']->id, 'component_id' => $f['catalog']->id, 'slot_code' => 'TONER_M', 'baseline_expected_clicks' => 10000, 'is_active' => true]);
        $mc2 = MachineComponent::create(['account_id' => $f['home']->id, 'machine_id' => $f['machine']->id, 'component_id' => $f['catalog']->id, 'slot_code' => 'TONER_M', 'source_type' => 'manual', 'status' => 'configured', 'active_key' => 'active', 'tracking_method' => 'counter_based', 'baseline_expected_clicks' => 10000]);

        $this->actingAs($attacker)->postJson("/api/v1/machine-components/{$mc2->id}/reconcile", ['profile_slot_id' => $slot2->id])->assertForbidden();
    }

    public function test_cross_account_owner_is_forbidden_on_initialize(): void
    {
        $f = $this->fixture();
        $attacker = $this->member($f['foreign'], 'owner');

        $this->actingAs($attacker)->postJson("/api/v1/machine-components/{$f['mc']->id}/lifecycles", ['started_at' => now()->toDateString()])->assertForbidden();
    }

    public function test_cross_account_owner_is_forbidden_on_reconciliation_candidate_read(): void
    {
        $f = $this->fixture();
        $attacker = $this->member($f['foreign'], 'owner');

        $this->actingAs($attacker)->getJson("/api/v1/machine-components/{$f['mc']->id}/reconciliation-candidate")->assertForbidden();
    }

    // --- B. SAME-ACCOUNT WRONG ROLE: technician/operator with real branch access must still be rejected on configuration-tier endpoints ---

    public function test_same_account_technician_is_forbidden_on_every_configuration_tier_endpoint(): void
    {
        $f = $this->fixture();
        $technician = $this->member($f['home'], 'technician', $f['branch']);

        foreach (array_keys($this->configurationTierRequests($f)) as $key) {
            $this->hit($f, $key, $technician)->assertForbidden();
        }
    }

    public function test_same_account_operator_is_forbidden_on_every_configuration_tier_endpoint(): void
    {
        $f = $this->fixture();
        $operator = $this->member($f['home'], 'operator', $f['branch']);

        foreach (array_keys($this->configurationTierRequests($f)) as $key) {
            $this->hit($f, $key, $operator)->assertForbidden();
        }
    }

    // --- C/D. AUTHORIZED ROLE and PLATFORM SUPERUSER: owner, admin, and superuser succeed ---

    public function test_home_account_owner_can_perform_configuration_mutations(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);

        $this->hit($f, 'exclude', $owner)->assertNoContent();
    }

    public function test_home_account_admin_can_perform_configuration_mutations(): void
    {
        $f = $this->fixture();
        $admin = $this->member($f['home'], 'admin', $f['branch']);

        $this->hit($f, 'sync', $admin)->assertOk();
    }

    public function test_platform_superuser_can_perform_configuration_mutations(): void
    {
        $f = $this->fixture();
        $superuser = $this->superuser();
        AccountMembership::create(['account_id' => $f['home']->id, 'user_id' => $superuser->id, 'role' => 'owner', 'status' => 'active', 'accepted_at' => now()]);

        $this->hit($f, 'remove', $superuser)->assertOk();
    }

    // --- Operational tier: initialize() allows a branch-scoped technician/operator, not just owner/admin ---

    public function test_same_account_technician_can_initialize_a_lifecycle_operational_tier(): void
    {
        $f = $this->fixture();
        $technician = $this->member($f['home'], 'technician', $f['branch']);

        $this->actingAs($technician)->postJson("/api/v1/machine-components/{$f['mc']->id}/lifecycles", ['started_at' => now()->toDateString()])->assertCreated();
    }

    public function test_same_account_operator_can_initialize_a_lifecycle_operational_tier(): void
    {
        $f = $this->fixture();
        $operator = $this->member($f['home'], 'operator', $f['branch']);

        $this->actingAs($operator)->postJson("/api/v1/machine-components/{$f['mc']->id}/lifecycles", ['started_at' => now()->toDateString()])->assertCreated();
    }

    // Read tier: reconciliationCandidate() allows a branch-scoped technician/operator (read-only, verified no writes).

    public function test_same_account_operator_can_read_reconciliation_candidate(): void
    {
        $f = $this->fixture();
        $operator = $this->member($f['home'], 'operator', $f['branch']);

        $this->actingAs($operator)->getJson("/api/v1/machine-components/{$f['mc']->id}/reconciliation-candidate")->assertOk();
    }

    // --- E. ARCHIVED/INACTIVE MEMBERSHIP: fails closed ---

    public function test_suspended_membership_is_forbidden_even_for_a_former_owner(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home'], 'owner', $f['branch']);
        AccountMembership::where('user_id', $owner->id)->update(['status' => 'suspended']);

        $this->hit($f, 'exclude', $owner)->assertForbidden();
    }

    public function test_deactivated_branch_assignment_is_forbidden_for_technician_initialize(): void
    {
        $f = $this->fixture();
        $technician = $this->member($f['home'], 'technician', $f['branch']);
        AccountMembershipBranch::where('account_id', $f['home']->id)->update(['is_active' => false]);

        $this->actingAs($technician)->postJson("/api/v1/machine-components/{$f['mc']->id}/lifecycles", ['started_at' => now()->toDateString()])->assertForbidden();
    }

    // --- clearExclusion() resolves its parent account/machine from the exclusion id itself, not a client-supplied value ---

    public function test_clear_exclusion_resolves_authorization_from_the_exclusion_record_itself(): void
    {
        $f = $this->fixture();
        $attacker = $this->member($f['foreign'], 'owner');

        $this->actingAs($attacker)->postJson("/api/v1/component-exclusions/{$f['exclusion']->id}/clear", [])->assertForbidden();
    }
}
