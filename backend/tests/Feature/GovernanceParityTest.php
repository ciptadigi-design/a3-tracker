<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\PlatformUserPrivilege;
use App\Models\User;
use App\Services\AccountAccessResolver;
use App\Services\BranchAccessResolver;
use App\Services\PlatformPrivilegeService;
use App\Services\ProvisionMember;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class GovernanceParityTest extends TestCase
{
    use RefreshDatabase;

    private function graph(): array
    {
        $a = Account::create(['code' => 'A', 'name' => 'Account A', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $b = Account::create(['code' => 'B', 'name' => 'Account B', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $ba = $a->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_active' => true]);
        $bb = $b->branches()->create(['code' => 'MAIN', 'name' => 'Other', 'is_active' => true]);
        $u = User::factory()->create(['username' => 'operator.a', 'status' => 'active']);
        $m = AccountMembership::create(['account_id' => $a->id, 'user_id' => $u->id, 'role' => 'operator', 'status' => 'active', 'accepted_at' => now()]);
        AccountMembershipBranch::create(['account_id' => $a->id, 'membership_id' => $m->id, 'branch_id' => $ba->id, 'is_active' => true]);

        return compact('a', 'b', 'ba', 'bb', 'u', 'm');
    }

    public function test_username_login_and_disabled_user_are_generic_and_denied(): void
    {
        $u = User::factory()->create(['username' => 'login.user', 'password' => 'secret-password', 'status' => 'active']);
        $this->postJson('/api/v1/auth/login', ['login' => 'LOGIN.USER', 'password' => 'secret-password'])->assertOk();
        $u->update(['status' => 'disabled']);
        $this->postJson('/api/v1/auth/login', ['login' => 'login.user', 'password' => 'secret-password'])->assertUnprocessable()->assertJsonPath('errors.login.0', 'Invalid credentials.');
    }

    public function test_cross_account_and_branch_access_fail_closed(): void
    {
        $g = $this->graph();
        $this->assertTrue(app(AccountAccessResolver::class)->canAccess($g['u'], $g['a']));
        $this->assertFalse(app(AccountAccessResolver::class)->canAccess($g['u'], $g['b']));
        $this->assertTrue(app(BranchAccessResolver::class)->canAccess($g['u'], $g['ba']));
        $this->assertFalse(app(BranchAccessResolver::class)->canAccess($g['u'], $g['bb']));
    }

    public function test_owner_is_not_platform_superuser_and_explicit_privilege_is_required(): void
    {
        $g = $this->graph();
        $g['m']->update(['role' => 'owner']);
        $this->assertFalse(app(PlatformPrivilegeService::class)->isSuperuser($g['u']));
        PlatformUserPrivilege::create(['user_id' => $g['u']->id, 'role' => 'superuser', 'is_active' => true]);
        $this->assertTrue(app(PlatformPrivilegeService::class)->isSuperuser($g['u']));
    }

    public function test_suspended_membership_and_archived_account_deny_access(): void
    {
        $g = $this->graph();
        $g['m']->update(['status' => 'suspended']);
        $this->assertFalse(app(AccountAccessResolver::class)->canAccess($g['u'], $g['a']));
        $g['m']->update(['status' => 'active']);
        $g['a']->update(['status' => 'archived']);
        $this->assertFalse(app(AccountAccessResolver::class)->canAccess($g['u'], $g['a']));
    }

    public function test_settings_gate_is_explicitly_superuser_only(): void
    {
        $g = $this->graph();
        $g['m']->update(['role' => 'owner']);
        $this->actingAs($g['u']);
        $this->assertFalse(Gate::forUser($g['u'])->allows('settings.access'));
        PlatformUserPrivilege::create(['user_id' => $g['u']->id, 'role' => 'superuser', 'is_active' => true]);
        $this->assertTrue(Gate::forUser($g['u'])->allows('settings.access'));
    }

    public function test_last_active_owner_cannot_be_demoted_or_suspended(): void
    {
        $g = $this->graph();
        $g['m']->update(['role' => 'owner']);
        $this->actingAs($g['u'])->patchJson('/api/v1/accounts/'.$g['a']->id.'/members/'.$g['m']->id, ['status' => 'suspended'])->assertStatus(409);
        $this->assertSame('active', $g['m']->fresh()->status);
    }

    public function test_disabled_session_is_revoked_and_superuser_has_no_implicit_branch_scope(): void
    {
        $g = $this->graph();
        PlatformUserPrivilege::create(['user_id' => $g['u']->id, 'role' => 'superuser', 'is_active' => true]);
        $this->actingAs($g['u']);
        $this->assertFalse(app(BranchAccessResolver::class)->canAccess($g['u'], $g['bb']));
        $g['u']->update(['status' => 'disabled']);
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_cross_tenant_assignment_is_rejected_by_composite_foreign_keys(): void
    {
        $g = $this->graph();
        $this->expectException(QueryException::class);
        AccountMembershipBranch::create(['account_id' => $g['a']->id, 'membership_id' => $g['m']->id, 'branch_id' => $g['bb']->id, 'is_active' => true]);
    }

    public function test_provisioning_is_idempotent_and_reactivates_membership(): void
    {
        $a = Account::create(['code' => 'PROV', 'name' => 'Provisioned', 'default_timezone' => 'Asia/Jakarta']);
        $b = $a->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_active' => true]);
        $actor = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $a->id, 'user_id' => $actor->id, 'role' => 'owner', 'status' => 'active', 'accepted_at' => now()]);
        $service = app(ProvisionMember::class);
        $data = ['name' => 'Member', 'email' => 'member@example.test', 'username' => 'member.one', 'password' => 'secret-password', 'role' => 'operator', 'branch_ids' => [$b->id]];
        $first = $service->execute($actor, $a, $data);
        $first->update(['status' => 'suspended']);
        $second = $service->execute($actor, $a, $data);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('active', $second->fresh()->status);
        $this->assertCount(1, AccountMembership::where(['account_id' => $a->id, 'user_id' => $second->user_id])->get());
    }

    public function test_member_provisioning_rejects_owner_escalation_and_invalid_branch(): void
    {
        $g = $this->graph();
        $this->actingAs($g['u']);
        $this->postJson('/api/v1/accounts/'.$g['a']->id.'/members', ['name' => 'Escalator', 'email' => 'escalator@example.test', 'username' => 'escalator', 'password' => 'secret-password', 'role' => 'owner', 'branch_ids' => [$g['ba']->id]])->assertUnprocessable();
        $this->postJson('/api/v1/accounts/'.$g['a']->id.'/members', ['name' => 'Cross', 'email' => 'cross@example.test', 'username' => 'cross.user', 'password' => 'secret-password', 'role' => 'operator', 'branch_ids' => [$g['bb']->id]])->assertForbidden();
    }

    public function test_archived_branch_and_cross_account_routes_fail_closed(): void
    {
        $g = $this->graph();
        $g['ba']->update(['is_active' => false, 'archived_at' => now()]);
        $this->assertFalse(app(BranchAccessResolver::class)->canAccess($g['u'], $g['ba']));
        $this->actingAs($g['u'])->getJson('/api/v1/accounts/'.$g['b']->id.'/branches')->assertForbidden();
    }

    public function test_me_payload_is_safe_and_explicit(): void
    {
        $g = $this->graph();
        $this->actingAs($g['u'])->getJson('/api/v1/me')->assertOk()->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email', 'username', 'status'], 'platform' => ['is_superuser'], 'memberships', 'accounts']])->assertJsonMissingPath('data.user.password');
    }
}
