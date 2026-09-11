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
 * M2.17.3 Scope A: Supplier identity stays account-owned; Account Owner/Admin
 * must be able to run routine supplier administration without a platform
 * Superuser, branch visibility must be correct once a supplier is assigned
 * to specific branches, and referenced suppliers must never be destructively
 * deletable while archiving stays available.
 */
class M2_17_3_SupplierGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private function accountWithBranches(): array
    {
        $account = Account::create(['code' => 'CG', 'name' => 'Cipta Grafika', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $tuparev = $account->branches()->create(['code' => 'TUP', 'name' => 'Tuparev', 'is_active' => true]);
        $graha = $account->branches()->create(['code' => 'GRH', 'name' => 'Graha', 'is_active' => true]);

        return compact('account', 'tuparev', 'graha');
    }

    private function member(Account $account, string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active', 'accepted_at' => now()]);

        return $user;
    }

    // Branch VISIBILITY (workspace/inventory access) and account-level supplier CAPABILITY
    // are independent axes: only 'owner' gets implicit all-branch visibility, so a
    // non-owner admin still needs an explicit AccountMembershipBranch grant per branch to
    // open a branch's inventory workspace, even though canManageOperational() already lets
    // that same admin manage account-wide supplier master data without one.
    private function adminWithBranches(Account $account, array $branches): User
    {
        $user = $this->member($account, 'admin');
        $membership = AccountMembership::where('account_id', $account->id)->where('user_id', $user->id)->firstOrFail();
        foreach ($branches as $branch) {
            AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $branch->id, 'is_active' => true]);
        }

        return $user;
    }

    public function test_account_admin_can_create_edit_and_archive_a_supplier_without_superuser(): void
    {
        $g = $this->accountWithBranches();
        $admin = $this->member($g['account'], 'admin');

        $created = $this->actingAs($admin)->postJson('/api/v1/inventory/suppliers', [
            'account_id' => $g['account']->id, 'code' => 'JFP', 'name' => 'Just_ForPrint', 'contact_name' => 'Dzikri', 'phone' => '+62 896-6054-4632',
        ])->assertOk();
        $supplierId = $created->json('data.id');

        $this->actingAs($admin)->putJson("/api/v1/inventory/suppliers/{$supplierId}", [
            'account_id' => $g['account']->id, 'code' => 'JFP', 'name' => 'Just ForPrint', 'contact_name' => 'Dzikri', 'phone' => '+62 896-6054-4632', 'email' => null,
        ])->assertOk()->assertJsonPath('data.name', 'Just ForPrint');

        $this->actingAs($admin)->putJson("/api/v1/inventory/suppliers/{$supplierId}", [
            'account_id' => $g['account']->id, 'code' => 'JFP', 'name' => 'Just ForPrint', 'is_active' => false,
        ])->assertOk();
        $this->assertFalse(InventorySupplier::findOrFail($supplierId)->is_active);
    }

    public function test_technician_cannot_manage_suppliers(): void
    {
        $g = $this->accountWithBranches();
        $technician = $this->member($g['account'], 'technician');

        $this->actingAs($technician)->postJson('/api/v1/inventory/suppliers', ['account_id' => $g['account']->id, 'code' => 'JFP', 'name' => 'Just_ForPrint'])->assertForbidden();
    }

    public function test_cross_account_supplier_access_is_forbidden(): void
    {
        $g = $this->accountWithBranches();
        $supplier = InventorySupplier::create(['account_id' => $g['account']->id, 'code' => 'JFP', 'name' => 'Just_ForPrint']);

        $otherAccount = Account::create(['code' => 'OTH', 'name' => 'Other Co', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $otherAdmin = $this->member($otherAccount, 'admin');

        $this->actingAs($otherAdmin)->putJson("/api/v1/inventory/suppliers/{$supplier->id}", ['account_id' => $otherAccount->id, 'code' => 'JFP', 'name' => 'Hijacked'])->assertNotFound();
        $this->actingAs($otherAdmin)->postJson("/api/v1/inventory/suppliers/{$supplier->id}/branches", ['branch_id' => Str::uuid()->toString()])->assertForbidden();
    }

    public function test_supplier_with_no_assignment_is_visible_to_every_branch(): void
    {
        $g = $this->accountWithBranches();
        $admin = $this->adminWithBranches($g['account'], [$g['tuparev'], $g['graha']]);
        InventorySupplier::create(['account_id' => $g['account']->id, 'code' => 'SS', 'name' => 'Supplier SS']);

        $tuparevWorkspace = $this->actingAs($admin)->getJson("/api/v1/accounts/{$g['account']->id}/branches/{$g['tuparev']->id}/inventory")->assertOk();
        $grahaWorkspace = $this->actingAs($admin)->getJson("/api/v1/accounts/{$g['account']->id}/branches/{$g['graha']->id}/inventory")->assertOk();

        $this->assertSame(['SS'], collect($tuparevWorkspace->json('data.suppliers'))->pluck('code')->all());
        $this->assertSame(['SS'], collect($grahaWorkspace->json('data.suppliers'))->pluck('code')->all());
    }

    public function test_assigning_a_supplier_to_specific_branches_narrows_visibility(): void
    {
        $g = $this->accountWithBranches();
        $admin = $this->adminWithBranches($g['account'], [$g['tuparev'], $g['graha']]);
        $ss = InventorySupplier::create(['account_id' => $g['account']->id, 'code' => 'SS', 'name' => 'Supplier SS']);
        InventorySupplier::create(['account_id' => $g['account']->id, 'code' => 'JFP', 'name' => 'Just_ForPrint']);

        // Supplier SS serves both Tuparev and Graha; JFP is only ever assigned to Graha.
        $this->actingAs($admin)->postJson("/api/v1/inventory/suppliers/{$ss->id}/branches", ['branch_id' => $g['tuparev']->id])->assertCreated();
        $this->actingAs($admin)->postJson("/api/v1/inventory/suppliers/{$ss->id}/branches", ['branch_id' => $g['graha']->id])->assertCreated();

        $jfp = InventorySupplier::where('code', 'JFP')->firstOrFail();
        $this->actingAs($admin)->postJson("/api/v1/inventory/suppliers/{$jfp->id}/branches", ['branch_id' => $g['graha']->id])->assertCreated();

        $tuparevWorkspace = $this->actingAs($admin)->getJson("/api/v1/accounts/{$g['account']->id}/branches/{$g['tuparev']->id}/inventory")->assertOk();
        $grahaWorkspace = $this->actingAs($admin)->getJson("/api/v1/accounts/{$g['account']->id}/branches/{$g['graha']->id}/inventory")->assertOk();

        $this->assertSame(['SS'], collect($tuparevWorkspace->json('data.suppliers'))->pluck('code')->all());
        $this->assertEqualsCanonicalizing(['SS', 'JFP'], collect($grahaWorkspace->json('data.suppliers'))->pluck('code')->all());

        $this->actingAs($admin)->deleteJson("/api/v1/inventory/suppliers/{$ss->id}/branches/{$g['tuparev']->id}")->assertNoContent();
        $tuparevAfterUnassign = $this->actingAs($admin)->getJson("/api/v1/accounts/{$g['account']->id}/branches/{$g['tuparev']->id}/inventory")->assertOk();
        $this->assertSame([], collect($tuparevAfterUnassign->json('data.suppliers'))->pluck('code')->all());
    }

    public function test_forged_cross_account_branch_assignment_is_rejected(): void
    {
        $g = $this->accountWithBranches();
        $admin = $this->member($g['account'], 'admin');
        $supplier = InventorySupplier::create(['account_id' => $g['account']->id, 'code' => 'SS', 'name' => 'Supplier SS']);

        $otherAccount = Account::create(['code' => 'OTH', 'name' => 'Other Co', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $foreignBranch = Branch::create(['account_id' => $otherAccount->id, 'code' => 'FRN', 'name' => 'Foreign Branch']);

        $this->actingAs($admin)->postJson("/api/v1/inventory/suppliers/{$supplier->id}/branches", ['branch_id' => $foreignBranch->id])->assertNotFound();
    }

    public function test_referenced_supplier_cannot_be_permanently_deleted_but_can_be_archived(): void
    {
        $g = $this->accountWithBranches();
        $admin = $this->adminWithBranches($g['account'], [$g['tuparev']]);
        $supplier = InventorySupplier::create(['account_id' => $g['account']->id, 'code' => 'JFP', 'name' => 'Just_ForPrint']);

        DB::table('purchases')->insert([
            'id' => (string) Str::uuid(), 'account_id' => $g['account']->id, 'branch_id' => $g['tuparev']->id, 'supplier_id' => $supplier->id,
            'purchase_number' => 'PUR-1', 'purchase_date' => now()->toDateString(), 'currency_code' => 'IDR', 'status' => 'draft',
            'client_request_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin)->deleteJson("/api/v1/inventory/suppliers/{$supplier->id}")->assertStatus(409);

        $this->actingAs($admin)->putJson("/api/v1/inventory/suppliers/{$supplier->id}", ['account_id' => $g['account']->id, 'code' => 'JFP', 'name' => 'Just_ForPrint', 'is_active' => false])->assertOk();
        $this->assertFalse(InventorySupplier::findOrFail($supplier->id)->is_active);

        // Historical purchase evidence must remain readable after archiving the supplier it references.
        $tuparevWorkspace = $this->actingAs($admin)->getJson("/api/v1/accounts/{$g['account']->id}/branches/{$g['tuparev']->id}/inventory")->assertOk();
        $this->assertSame(['PUR-1'], collect($tuparevWorkspace->json('data.purchases'))->pluck('purchase_number')->all());
    }

    public function test_unreferenced_supplier_can_be_permanently_deleted(): void
    {
        $g = $this->accountWithBranches();
        $admin = $this->member($g['account'], 'admin');
        $supplier = InventorySupplier::create(['account_id' => $g['account']->id, 'code' => 'UNUSED', 'name' => 'Unused Supplier']);

        $this->actingAs($admin)->deleteJson("/api/v1/inventory/suppliers/{$supplier->id}")->assertNoContent();
        $this->assertNull(InventorySupplier::find($supplier->id));
    }
}
