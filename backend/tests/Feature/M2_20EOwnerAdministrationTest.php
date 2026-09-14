<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\PlatformUserPrivilege;
use App\Models\User;
use App\Services\GovernanceAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class M2_20EOwnerAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private array $g = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['a', 'b'] as $key) {
            $account = $this->g[$key] = Account::create(['code' => 'M220E_'.$key, 'name' => 'Tenant '.$key, 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
            $branch = $this->g[$key.'branch'] = $account->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_active' => true]);
            foreach (['owner', 'admin', 'technician', 'operator'] as $role) {
                $user = $this->g[$key.$role] = User::factory()->create(['status' => 'active']);
                $membership = $this->g[$key.$role.'m'] = $account->memberships()->create(['user_id' => $user->id, 'role' => $role, 'status' => 'active', 'accepted_at' => now()]);
                if ($role !== 'owner') {
                    $membership->branchAssignments()->create(['account_id' => $account->id, 'branch_id' => $branch->id, 'is_active' => true]);
                }
            }
        }
        $this->g['platform'] = User::factory()->create(['status' => 'active']);
        PlatformUserPrivilege::create(['user_id' => $this->g['platform']->id, 'role' => 'superuser', 'is_active' => true]);
    }

    private function base(string $account = 'a'): string
    {
        return '/api/v1/accounts/'.$this->g[$account]->id;
    }

    public function test_settings_and_section_data_follow_effective_capabilities(): void
    {
        $owner = $this->actingAs($this->g['aowner'])->getJson($this->base().'/settings')->assertOk()->json('data');
        $this->assertNotEmpty($owner['members']);
        $this->assertSame([], $owner['models']);
        $this->assertSame([], $owner['manufacturers']);

        $admin = $this->actingAs($this->g['aadmin'])->getJson($this->base().'/settings')->assertOk()->json('data');
        $this->assertSame([], $admin['members']);
        $this->assertSame([], $admin['models']);
        foreach (['atechnician', 'aoperator'] as $actor) {
            $this->actingAs($this->g[$actor])->getJson($this->base().'/settings')->assertForbidden();
        }
        $this->actingAs($this->g['aowner'])->getJson($this->base('b').'/settings')->assertForbidden();

        $caps = $this->getJson('/api/v1/me')->assertOk()->json('data.capabilities.'.$this->g['a']->id);
        foreach (['settings.view', 'account.manage', 'members.manage', 'branches.manage', 'operational_people.manage', 'settings.policy.manage', 'audit.view'] as $capability) {
            $this->assertTrue($caps[$capability], $capability);
        }
        $this->assertFalse($caps['catalog.global.manage']);
    }

    public function test_owner_updates_only_safe_profile_fields_and_change_is_audited(): void
    {
        $this->actingAs($this->g['aowner'])
            ->patchJson($this->base().'/profile', ['name' => 'Customer Workspace', 'default_timezone' => 'Asia/Makassar', 'status' => 'archived', 'code' => 'HACK'])
            ->assertOk()->assertJsonPath('data.name', 'Customer Workspace')->assertJsonPath('data.default_timezone', 'Asia/Makassar');
        $this->assertDatabaseHas('accounts', ['id' => $this->g['a']->id, 'code' => 'M220E_A', 'status' => 'active']);
        $this->assertDatabaseHas('governance_audit_logs', ['account_id' => $this->g['a']->id, 'actor_user_id' => $this->g['aowner']->id, 'action' => 'account.profile_updated']);
        $this->patchJson($this->base('b').'/profile', ['name' => 'Foreign', 'default_timezone' => 'UTC'])->assertForbidden();
        $this->patchJson($this->base().'/profile', ['name' => 'Invalid', 'default_timezone' => 'Mars/Olympus'])->assertUnprocessable();
    }

    public function test_profile_update_rolls_back_when_required_audit_fails(): void
    {
        $before = $this->g['a']->only(['name', 'default_timezone']);
        $this->mock(GovernanceAudit::class)->makePartial()->shouldReceive('record')->once()->andThrow(new \RuntimeException('Synthetic audit failure'));
        $this->actingAs($this->g['aowner'])->patchJson($this->base().'/profile', ['name' => 'Must Roll Back', 'default_timezone' => 'UTC'])->assertStatus(500);
        $this->assertSame($before, $this->g['a']->fresh()->only(['name', 'default_timezone']));
        $this->assertDatabaseCount('governance_audit_logs', 0);
    }

    public function test_owner_branch_management_is_tenant_scoped_and_audited(): void
    {
        $this->actingAs($this->g['aowner']);
        $branch = $this->postJson($this->base().'/branches', ['code' => 'SECOND', 'name' => 'Second', 'timezone' => 'Asia/Jayapura'])->assertCreated()->json('data');
        $this->putJson($this->base().'/branches/'.$branch['id'], ['name' => 'Renamed', 'is_active' => false])->assertOk();
        $this->assertDatabaseHas('branches', ['id' => $branch['id'], 'account_id' => $this->g['a']->id, 'is_active' => false]);
        $this->putJson($this->base('b').'/branches/'.$this->g['bbranch']->id, ['name' => 'Foreign'])->assertForbidden();
        foreach (['branch.created', 'branch.archived'] as $action) {
            $this->assertDatabaseHas('governance_audit_logs', ['account_id' => $this->g['a']->id, 'action' => $action]);
        }
    }

    public function test_owner_membership_workflow_does_not_grant_global_identity_authority(): void
    {
        $this->actingAs($this->g['aowner']);
        $member = $this->postJson($this->base().'/members', [
            'name' => 'Pilot Operator', 'email' => 'pilot@example.test', 'username' => 'pilot.operator',
            'password' => 'synthetic-password', 'role' => 'operator', 'branch_ids' => [$this->g['abranch']->id],
        ])->assertCreated()->json('data');
        $this->patchJson($this->base().'/members/'.$member['id'], ['role' => 'admin', 'status' => 'active', 'branch_ids' => [$this->g['abranch']->id]])->assertOk();
        $this->assertDatabaseHas('account_memberships', ['id' => $member['id'], 'role' => 'admin']);
        $this->patchJson($this->base().'/members/'.$member['id'], ['role' => 'admin', 'status' => 'suspended', 'branch_ids' => [$this->g['abranch']->id]])->assertOk();
        $this->patchJson($this->base().'/members/'.$member['id'], ['role' => 'admin', 'status' => 'active', 'branch_ids' => [$this->g['abranch']->id]])->assertOk();

        $before = DB::table('account_membership_branches')->where('membership_id', $member['id'])->get()->toJson();
        $this->patchJson($this->base().'/members/'.$member['id'], ['branch_ids' => [$this->g['bbranch']->id]])->assertUnprocessable();
        $this->assertSame($before, DB::table('account_membership_branches')->where('membership_id', $member['id'])->get()->toJson());

        $original = User::findOrFail($member['user_id']);
        $this->patchJson($this->base().'/members/'.$member['id'], ['username' => 'global.change'])->assertForbidden();
        $this->patchJson($this->base().'/members/'.$member['id'].'/email', ['email' => 'global@example.test'])->assertForbidden();
        $this->postJson($this->base().'/members/'.$member['id'].'/password', ['password' => 'changed-password', 'password_confirmation' => 'changed-password'])->assertForbidden();
        $this->assertSame('pilot.operator', $original->fresh()->username);
        $this->assertSame('pilot@example.test', $original->fresh()->email);

        $this->postJson($this->base().'/members', [
            'name' => 'Collision', 'email' => $this->g['boperator']->email, 'username' => 'foreign.identity',
            'password' => 'synthetic-password', 'role' => 'operator', 'branch_ids' => [$this->g['abranch']->id],
        ])->assertStatus(409);
        $this->postJson('/api/v1/platform/accounts/'.$this->g['a']->id.'/members', ['user_id' => $this->g['boperator']->id, 'role' => 'operator', 'branch_ids' => [$this->g['abranch']->id]])->assertForbidden();
        $this->postJson('/api/v1/platform/bootstrap-superuser', ['user_id' => $original->id])->assertForbidden();
        $this->postJson('/api/v1/manufacturers', ['code' => 'GLOBAL', 'name' => 'Global'])->assertForbidden();
        $this->assertDatabaseMissing('platform_user_privileges', ['user_id' => $original->id]);
    }

    public function test_owner_operational_people_are_separate_scoped_and_audited(): void
    {
        $this->actingAs($this->g['aowner']);
        $userCount = User::count();
        $person = $this->postJson($this->base().'/operational-people', ['name' => 'Counter PIC', 'code' => 'PIC', 'linked_user_id' => $this->g['boperator']->id])->assertCreated()->json('data');
        $this->assertDatabaseHas('operational_people', ['id' => $person['id'], 'account_id' => $this->g['a']->id, 'linked_user_id' => null]);
        $this->putJson($this->base().'/operational-people/'.$person['id'], ['name' => 'Renamed PIC', 'code' => 'PIC'])->assertOk();
        $this->putJson('/api/v1/operational-people/'.$person['id'].'/branches', ['assignments' => [['branch_id' => $this->g['abranch']->id, 'can_record_counter' => true]]])->assertOk();
        $this->putJson('/api/v1/operational-people/'.$person['id'].'/branches', ['assignments' => [['branch_id' => $this->g['bbranch']->id, 'can_record_counter' => true]]])->assertUnprocessable();
        $this->assertDatabaseMissing('operational_person_branches', ['person_id' => $person['id'], 'branch_id' => $this->g['bbranch']->id]);
        $this->actingAs($this->g['bowner'])->putJson($this->base().'/operational-people/'.$person['id'], ['name' => 'Foreign edit'])->assertForbidden();
        $this->actingAs($this->g['aowner'])->patchJson('/api/v1/operational-people/'.$person['id'].'/status', ['is_active' => false])->assertOk();
        $this->patchJson('/api/v1/operational-people/'.$person['id'].'/status', ['is_active' => true])->assertOk();
        $this->assertDatabaseHas('governance_audit_logs', ['account_id' => $this->g['a']->id, 'action' => 'operational_person.created']);
        $this->assertDatabaseHas('governance_audit_logs', ['account_id' => $this->g['a']->id, 'action' => 'operational_person.branch_assignment_changed']);
        $this->assertDatabaseHas('governance_audit_logs', ['account_id' => $this->g['a']->id, 'action' => 'operational_person.deactivated']);
        $this->assertDatabaseHas('governance_audit_logs', ['account_id' => $this->g['a']->id, 'action' => 'operational_person.reactivated']);
        $this->assertSame($userCount, User::count());
    }

    public function test_policy_audit_and_last_owner_guards_remain_authoritative(): void
    {
        $this->actingAs($this->g['aowner']);
        $this->patchJson($this->base().'/settings/policy', ['operator_can_log_errors' => true])->assertOk();
        $this->getJson($this->base().'/audit')->assertOk()->assertJsonPath('data.data.0.account_id', $this->g['a']->id);
        $this->patchJson($this->base('b').'/settings/policy', ['operator_can_log_errors' => true])->assertForbidden();
        $this->getJson($this->base('b').'/audit')->assertForbidden();

        foreach ([['role' => 'admin'], ['status' => 'suspended'], ['status' => 'revoked']] as $change) {
            $this->patchJson($this->base().'/members/'.$this->g['aownerm']->id, $change)->assertStatus(409);
            $this->assertDatabaseHas('account_memberships', ['id' => $this->g['aownerm']->id, 'role' => 'owner', 'status' => 'active']);
        }
    }

    public function test_multi_account_owner_capabilities_do_not_leak_into_operator_context(): void
    {
        $membership = $this->g['b']->memberships()->create(['user_id' => $this->g['aowner']->id, 'role' => 'operator', 'status' => 'active', 'accepted_at' => now()]);
        $membership->branchAssignments()->create(['account_id' => $this->g['b']->id, 'branch_id' => $this->g['bbranch']->id, 'is_active' => true]);
        $capabilities = $this->actingAs($this->g['aowner'])->getJson('/api/v1/me')->assertOk()->json('data.capabilities');
        $this->assertTrue($capabilities[$this->g['a']->id]['settings.view']);
        $this->assertFalse($capabilities[$this->g['b']->id]['settings.view']);
        $this->getJson($this->base().'/settings')->assertOk();
        $this->getJson($this->base('b').'/settings')->assertForbidden();
    }

    public function test_fresh_zero_branch_tenant_is_self_sufficient_after_platform_provisioning(): void
    {
        $account = Account::create(['code' => 'FRESH', 'name' => 'Fresh Tenant', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $owner = User::factory()->create(['status' => 'active']);
        $account->memberships()->create(['user_id' => $owner->id, 'role' => 'owner', 'status' => 'active', 'accepted_at' => now()]);
        $this->actingAs($owner);

        $capabilities = $this->getJson('/api/v1/me')->assertOk()->json('data.capabilities');
        $this->assertTrue($capabilities[$account->id]['settings.view']);
        $this->getJson('/api/v1/accounts/'.$account->id.'/branches')->assertOk()->assertJsonCount(0, 'data.data');
        $this->getJson('/api/v1/accounts/'.$account->id.'/settings')->assertOk()->assertJsonCount(0, 'data.branches');
        $branch = $this->postJson('/api/v1/accounts/'.$account->id.'/branches', ['code' => 'FIRST', 'name' => 'First Branch'])->assertCreated()->json('data');
        $this->getJson('/api/v1/accounts/'.$account->id.'/branches')->assertOk()->assertJsonCount(1, 'data.data');
        $person = $this->postJson('/api/v1/accounts/'.$account->id.'/operational-people', ['name' => 'First PIC'])->assertCreated()->json('data');
        $this->putJson('/api/v1/operational-people/'.$person['id'].'/branches', ['assignments' => [['branch_id' => $branch['id'], 'can_record_counter' => true]]])->assertOk();
        $member = $this->postJson('/api/v1/accounts/'.$account->id.'/members', [
            'name' => 'First Operator', 'email' => 'fresh.operator@example.test', 'username' => 'fresh.operator',
            'password' => 'synthetic-password', 'role' => 'operator', 'branch_ids' => [$branch['id']],
        ])->assertCreated()->json('data');
        $this->patchJson('/api/v1/accounts/'.$account->id.'/settings/policy', ['operator_can_log_errors' => true])->assertOk();
        $this->getJson('/api/v1/accounts/'.$account->id.'/audit')->assertOk()->assertJsonFragment(['action' => 'membership.created']);

        $operator = User::findOrFail($member['user_id']);
        $operatorCapabilities = $this->actingAs($operator)->getJson('/api/v1/me')->assertOk()->json('data.capabilities');
        $this->assertTrue($operatorCapabilities[$account->id]['incidents.create']);
        $this->assertFalse($operatorCapabilities[$account->id]['settings.view']);
        $this->getJson('/api/v1/accounts/'.$account->id.'/settings')->assertForbidden();
    }
}
