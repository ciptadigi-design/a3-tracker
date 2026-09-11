<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Branch;
use App\Models\InventorySupplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * M2.17.4.1: a Production acceptance test found the Supplier Master list ignoring
 * branch context entirely (showing every account supplier regardless of the active
 * branch) while the Purchase picker already enforced branch eligibility correctly.
 * This suite proves both views now share the identical canonical eligibility rule
 * (InventorySupplier::scopeVisibleToBranch), that historical Purchase evidence still
 * resolves supplier names after a supplier's branch availability changes, and that
 * cross-account/forged-branch requests remain fail-closed.
 */
class M2_17_4_1_SupplierBranchVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $account = Account::create(['code' => 'CG', 'name' => 'Cipta Grafika', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $a1 = $account->branches()->create(['code' => 'TUP', 'name' => 'Tuparev', 'is_active' => true]);
        $a2 = $account->branches()->create(['code' => 'GRH', 'name' => 'Graha', 'is_active' => true]);

        $global = InventorySupplier::create(['account_id' => $account->id, 'code' => 'GLOBAL', 'name' => 'Global Supplier']);
        $tuparevOnly = InventorySupplier::create(['account_id' => $account->id, 'code' => 'TUPAREV_ONLY', 'name' => 'Tuparev Only Supplier']);
        $tuparevOnly->branchAssignments()->create(['account_id' => $account->id, 'branch_id' => $a1->id]);
        $grahaOnly = InventorySupplier::create(['account_id' => $account->id, 'code' => 'GRAHA_ONLY', 'name' => 'Graha Only Supplier']);
        $grahaOnly->branchAssignments()->create(['account_id' => $account->id, 'branch_id' => $a2->id]);
        $both = InventorySupplier::create(['account_id' => $account->id, 'code' => 'BOTH', 'name' => 'Both Branches Supplier']);
        $both->branchAssignments()->create(['account_id' => $account->id, 'branch_id' => $a1->id]);
        $both->branchAssignments()->create(['account_id' => $account->id, 'branch_id' => $a2->id]);
        $archivedGlobal = InventorySupplier::create(['account_id' => $account->id, 'code' => 'ARCHIVED_GLOBAL', 'name' => 'Archived Global Supplier', 'is_active' => false]);
        $archivedTuparev = InventorySupplier::create(['account_id' => $account->id, 'code' => 'ARCHIVED_TUPAREV', 'name' => 'Archived Tuparev Supplier', 'is_active' => false]);
        $archivedTuparev->branchAssignments()->create(['account_id' => $account->id, 'branch_id' => $a1->id]);

        $otherAccount = Account::create(['code' => 'OTH', 'name' => 'Other Co', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $otherBranch = Branch::create(['account_id' => $otherAccount->id, 'code' => 'FRN', 'name' => 'Foreign Branch', 'is_active' => true]);
        $otherSupplier = InventorySupplier::create(['account_id' => $otherAccount->id, 'code' => 'OTHER_ACCOUNT_SUPPLIER', 'name' => 'Other Account Supplier']);

        // Branch VISIBILITY (workspace access) is a separate axis from account-level
        // supplier CAPABILITY: only 'owner' gets implicit all-branch access, so a
        // non-owner admin still needs an explicit AccountMembershipBranch grant per
        // branch (see M2_17_3_SupplierGovernanceTest for the same pattern).
        $admin = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $account->id, 'user_id' => $admin->id, 'role' => 'admin', 'status' => 'active', 'accepted_at' => now()]);
        AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $a1->id, 'is_active' => true]);
        AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $a2->id, 'is_active' => true]);

        return compact('account', 'a1', 'a2', 'global', 'tuparevOnly', 'grahaOnly', 'both', 'archivedGlobal', 'archivedTuparev', 'otherAccount', 'otherBranch', 'otherSupplier', 'admin');
    }

    public function test_supplier_master_branch_a1_returns_exactly_the_eligible_and_cross_account_isolated_set(): void
    {
        $f = $this->fixtures();

        $response = $this->actingAs($f['admin'])->getJson("/api/v1/inventory/suppliers?account_id={$f['account']->id}&branch_id={$f['a1']->id}")->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();

        $this->assertEqualsCanonicalizing(['GLOBAL', 'TUPAREV_ONLY', 'BOTH', 'ARCHIVED_GLOBAL', 'ARCHIVED_TUPAREV'], $codes);
        $this->assertNotContains('GRAHA_ONLY', $codes);
        $this->assertNotContains('OTHER_ACCOUNT_SUPPLIER', $codes);
    }

    public function test_supplier_master_branch_a2_returns_exactly_the_eligible_and_cross_account_isolated_set(): void
    {
        $f = $this->fixtures();

        $response = $this->actingAs($f['admin'])->getJson("/api/v1/inventory/suppliers?account_id={$f['account']->id}&branch_id={$f['a2']->id}")->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();

        $this->assertEqualsCanonicalizing(['GLOBAL', 'GRAHA_ONLY', 'BOTH', 'ARCHIVED_GLOBAL'], $codes);
        $this->assertNotContains('TUPAREV_ONLY', $codes);
        $this->assertNotContains('ARCHIVED_TUPAREV', $codes);
        $this->assertNotContains('OTHER_ACCOUNT_SUPPLIER', $codes);
    }

    public function test_purchase_picker_matches_supplier_master_active_eligibility_for_both_branches(): void
    {
        $f = $this->fixtures();

        $a1Workspace = $this->actingAs($f['admin'])->getJson("/api/v1/accounts/{$f['account']->id}/branches/{$f['a1']->id}/inventory")->assertOk();
        $this->assertEqualsCanonicalizing(['GLOBAL', 'TUPAREV_ONLY', 'BOTH'], collect($a1Workspace->json('data.suppliers'))->pluck('code')->all());

        $a2Workspace = $this->actingAs($f['admin'])->getJson("/api/v1/accounts/{$f['account']->id}/branches/{$f['a2']->id}/inventory")->assertOk();
        $this->assertEqualsCanonicalizing(['GLOBAL', 'GRAHA_ONLY', 'BOTH'], collect($a2Workspace->json('data.suppliers'))->pluck('code')->all());
    }

    public function test_include_ineligible_returns_the_full_account_list_for_an_admin_only(): void
    {
        $f = $this->fixtures();

        $response = $this->actingAs($f['admin'])->getJson("/api/v1/inventory/suppliers?account_id={$f['account']->id}&branch_id={$f['a1']->id}&include_ineligible=1")->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();

        $this->assertEqualsCanonicalizing(['GLOBAL', 'TUPAREV_ONLY', 'GRAHA_ONLY', 'BOTH', 'ARCHIVED_GLOBAL', 'ARCHIVED_TUPAREV'], $codes);
    }

    public function test_include_ineligible_is_forbidden_for_a_non_managing_member(): void
    {
        $f = $this->fixtures();
        $technician = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $f['account']->id, 'user_id' => $technician->id, 'role' => 'technician', 'status' => 'active', 'accepted_at' => now()]);
        AccountMembershipBranch::create(['account_id' => $f['account']->id, 'membership_id' => $membership->id, 'branch_id' => $f['a1']->id, 'is_active' => true]);

        $this->actingAs($technician)->getJson("/api/v1/inventory/suppliers?account_id={$f['account']->id}&branch_id={$f['a1']->id}&include_ineligible=1")->assertForbidden();
        // The normal (non-ineligible) branch-filtered read is still allowed for any active member.
        $this->actingAs($technician)->getJson("/api/v1/inventory/suppliers?account_id={$f['account']->id}&branch_id={$f['a1']->id}")->assertOk();
    }

    public function test_forged_cross_account_branch_id_is_rejected(): void
    {
        $f = $this->fixtures();

        $this->actingAs($f['admin'])->getJson("/api/v1/inventory/suppliers?account_id={$f['account']->id}&branch_id={$f['otherBranch']->id}")->assertNotFound();
    }

    public function test_cross_account_member_cannot_read_another_accounts_supplier_master(): void
    {
        $f = $this->fixtures();
        $otherAdmin = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $f['otherAccount']->id, 'user_id' => $otherAdmin->id, 'role' => 'admin', 'status' => 'active', 'accepted_at' => now()]);

        $this->actingAs($otherAdmin)->getJson("/api/v1/inventory/suppliers?account_id={$f['account']->id}&branch_id={$f['a1']->id}")->assertForbidden();
    }

    public function test_historical_purchase_supplier_snapshot_survives_a_branch_eligibility_change(): void
    {
        $f = $this->fixtures();

        // Historical Purchase against TUPAREV_ONLY while it is still eligible for A1.
        DB::table('purchases')->insert([
            'id' => (string) Str::uuid(), 'account_id' => $f['account']->id, 'branch_id' => $f['a1']->id, 'supplier_id' => $f['tuparevOnly']->id,
            'purchase_number' => 'PUR-HIST-1', 'purchase_date' => now()->toDateString(), 'currency_code' => 'IDR', 'status' => 'draft',
            'client_request_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $before = $this->actingAs($f['admin'])->getJson("/api/v1/accounts/{$f['account']->id}/branches/{$f['a1']->id}/inventory")->assertOk();
        $this->assertContains('TUPAREV_ONLY', collect($before->json('data.suppliers'))->pluck('code')->all());
        $historicalBefore = collect($before->json('data.purchases'))->firstWhere('purchase_number', 'PUR-HIST-1');
        $this->assertSame('Tuparev Only Supplier', $historicalBefore['supplier_name_snapshot']);

        // Reassign the supplier so it is no longer eligible for A1 (now Graha-only).
        $f['tuparevOnly']->branchAssignments()->where('branch_id', $f['a1']->id)->delete();
        $f['tuparevOnly']->branchAssignments()->create(['account_id' => $f['account']->id, 'branch_id' => $f['a2']->id]);

        $after = $this->actingAs($f['admin'])->getJson("/api/v1/accounts/{$f['account']->id}/branches/{$f['a1']->id}/inventory")->assertOk();
        // New-purchase eligibility (the picker) correctly drops it from A1...
        $this->assertNotContains('TUPAREV_ONLY', collect($after->json('data.suppliers'))->pluck('code')->all());
        // ...but the historical Purchase's supplier snapshot must still resolve correctly -
        // this is the exact regression a naive "pass the filtered list into the summary
        // builder" implementation would introduce (silently turning it into "Unknown supplier").
        $historicalAfter = collect($after->json('data.purchases'))->firstWhere('purchase_number', 'PUR-HIST-1');
        $this->assertSame('Tuparev Only Supplier', $historicalAfter['supplier_name_snapshot']);
        $this->assertSame($f['tuparevOnly']->id, $historicalAfter['supplier_id']);

        // Supplier Master for A1 also correctly excludes it now, without touching the FK.
        $masterAfter = $this->actingAs($f['admin'])->getJson("/api/v1/inventory/suppliers?account_id={$f['account']->id}&branch_id={$f['a1']->id}")->assertOk();
        $this->assertNotContains('TUPAREV_ONLY', collect($masterAfter->json('data'))->pluck('code')->all());
        $this->assertNotNull(InventorySupplier::find($f['tuparevOnly']->id));
    }
}
