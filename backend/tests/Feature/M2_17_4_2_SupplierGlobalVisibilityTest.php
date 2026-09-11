<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\InventorySupplier;
use App\Models\PlatformUserPrivilege;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M2.17.4.2: a Production spot-check found the Supplier Master returning "0 active
 * suppliers" for Cipta Digital Tuparev even though Production has 9 active suppliers
 * and supplier_branch_assignments has zero rows - meaning every supplier is global
 * per the locked semantics and should appear everywhere. This suite proves that
 * result was a frontend error-state bug (see inventoryBootstrapResilienceContract.
 * test.js), not a backend query defect, by reproducing the exact Production shape
 * (N active suppliers, zero assignments) and asserting the full count is returned for
 * every branch, for both an Account Admin and a platform Superuser.
 */
class M2_17_4_2_SupplierGlobalVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $account = Account::create(['code' => 'CG', 'name' => 'Cipta Grafika', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $tuparev = $account->branches()->create(['code' => 'TUP', 'name' => 'Cipta Digital Tuparev', 'is_active' => true]);
        $graha = $account->branches()->create(['code' => 'GRH', 'name' => 'Cipta Graha', 'is_active' => true]);

        $admin = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $account->id, 'user_id' => $admin->id, 'role' => 'admin', 'status' => 'active', 'accepted_at' => now()]);
        AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $tuparev->id, 'is_active' => true]);
        AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $graha->id, 'is_active' => true]);

        return compact('account', 'tuparev', 'graha', 'admin');
    }

    public function test_nine_active_global_suppliers_with_zero_assignments_all_appear_in_every_branch(): void
    {
        $f = $this->fixtures();
        // Exactly the Production shape: N active suppliers, zero rows in
        // supplier_branch_assignments.
        for ($i = 1; $i <= 9; $i++) {
            InventorySupplier::create(['account_id' => $f['account']->id, 'code' => "SUP-$i", 'name' => "Supplier $i"]);
        }
        $this->assertSame(0, DB::table('supplier_branch_assignments')->count());

        $tuparevResponse = $this->actingAs($f['admin'])->getJson("/api/v1/inventory/suppliers?account_id={$f['account']->id}&branch_id={$f['tuparev']->id}")->assertOk();
        $grahaResponse = $this->actingAs($f['admin'])->getJson("/api/v1/inventory/suppliers?account_id={$f['account']->id}&branch_id={$f['graha']->id}")->assertOk();

        $this->assertCount(9, $tuparevResponse->json('data'));
        $this->assertCount(9, $grahaResponse->json('data'));

        $tuparevWorkspace = $this->actingAs($f['admin'])->getJson("/api/v1/accounts/{$f['account']->id}/branches/{$f['tuparev']->id}/inventory")->assertOk();
        $this->assertCount(9, $tuparevWorkspace->json('data.suppliers'));
    }

    public function test_mixed_global_and_restricted_suppliers_match_the_exact_expected_sets_for_both_branches(): void
    {
        $f = $this->fixtures();
        $global1 = InventorySupplier::create(['account_id' => $f['account']->id, 'code' => 'GLOBAL_1', 'name' => 'Global 1']);
        $global2 = InventorySupplier::create(['account_id' => $f['account']->id, 'code' => 'GLOBAL_2', 'name' => 'Global 2']);
        $tuparevOnly = InventorySupplier::create(['account_id' => $f['account']->id, 'code' => 'TUPAREV_ONLY', 'name' => 'Tuparev Only']);
        $tuparevOnly->branchAssignments()->create(['account_id' => $f['account']->id, 'branch_id' => $f['tuparev']->id]);
        $grahaOnly = InventorySupplier::create(['account_id' => $f['account']->id, 'code' => 'GRAHA_ONLY', 'name' => 'Graha Only']);
        $grahaOnly->branchAssignments()->create(['account_id' => $f['account']->id, 'branch_id' => $f['graha']->id]);
        $both = InventorySupplier::create(['account_id' => $f['account']->id, 'code' => 'BOTH', 'name' => 'Both']);
        $both->branchAssignments()->create(['account_id' => $f['account']->id, 'branch_id' => $f['tuparev']->id]);
        $both->branchAssignments()->create(['account_id' => $f['account']->id, 'branch_id' => $f['graha']->id]);

        // Supplier Master
        $masterTuparev = $this->actingAs($f['admin'])->getJson("/api/v1/inventory/suppliers?account_id={$f['account']->id}&branch_id={$f['tuparev']->id}")->assertOk();
        $this->assertEqualsCanonicalizing(['GLOBAL_1', 'GLOBAL_2', 'TUPAREV_ONLY', 'BOTH'], collect($masterTuparev->json('data'))->pluck('code')->all());
        $masterGraha = $this->actingAs($f['admin'])->getJson("/api/v1/inventory/suppliers?account_id={$f['account']->id}&branch_id={$f['graha']->id}")->assertOk();
        $this->assertEqualsCanonicalizing(['GLOBAL_1', 'GLOBAL_2', 'GRAHA_ONLY', 'BOTH'], collect($masterGraha->json('data'))->pluck('code')->all());

        // Purchase picker/workspace must use the identical eligibility semantics.
        $pickerTuparev = $this->actingAs($f['admin'])->getJson("/api/v1/accounts/{$f['account']->id}/branches/{$f['tuparev']->id}/inventory")->assertOk();
        $this->assertEqualsCanonicalizing(['GLOBAL_1', 'GLOBAL_2', 'TUPAREV_ONLY', 'BOTH'], collect($pickerTuparev->json('data.suppliers'))->pluck('code')->all());
        $pickerGraha = $this->actingAs($f['admin'])->getJson("/api/v1/accounts/{$f['account']->id}/branches/{$f['graha']->id}/inventory")->assertOk();
        $this->assertEqualsCanonicalizing(['GLOBAL_1', 'GLOBAL_2', 'GRAHA_ONLY', 'BOTH'], collect($pickerGraha->json('data.suppliers'))->pluck('code')->all());
    }

    public function test_platform_superuser_sees_the_same_global_supplier_set_with_no_special_bypass(): void
    {
        $f = $this->fixtures();
        for ($i = 1; $i <= 9; $i++) {
            InventorySupplier::create(['account_id' => $f['account']->id, 'code' => "SUP-$i", 'name' => "Supplier $i"]);
        }

        // A platform superuser is not automatically a member of every account (see
        // AccountAccessResolver::membership()/BranchAccessResolver::canAccess(), which
        // both fail-closed without a real AccountMembership) - the Production report's
        // "Superuser / Workspace Owner context" describes a user who is BOTH, which is
        // the realistic case: no special superuser-only bypass is introduced or needed
        // here, ownership alone already grants full branch access.
        $superuser = User::factory()->create(['status' => 'active']);
        PlatformUserPrivilege::create(['user_id' => $superuser->id, 'role' => 'superuser', 'is_active' => true]);
        AccountMembership::create(['account_id' => $f['account']->id, 'user_id' => $superuser->id, 'role' => 'owner', 'status' => 'active', 'accepted_at' => now()]);

        $response = $this->actingAs($superuser)->getJson("/api/v1/inventory/suppliers?account_id={$f['account']->id}&branch_id={$f['tuparev']->id}")->assertOk();
        $this->assertCount(9, $response->json('data'));
    }
}
