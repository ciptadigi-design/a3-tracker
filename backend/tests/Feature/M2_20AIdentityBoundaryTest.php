<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\PlatformUserPrivilege;
use App\Models\User;
use App\Services\GovernanceAudit;
use App\Services\PlatformPrivilegeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class M2_20AIdentityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private array $g;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['a', 'b'] as $key) {
            $a = Account::create(['code' => strtoupper($key), 'name' => 'Synthetic Tenant '.$key, 'status' => 'active']);
            $this->g[$key] = $a;
            $this->g[$key.'branch'] = $a->branches()->create(['code' => 'MAIN', 'name' => 'Synthetic branch', 'is_active' => true]);
            foreach (['owner', 'staff'] as $role) {
                $u = User::factory()->create(['email' => "$key.$role@example.test", 'username' => "$key.$role", 'status' => 'active', 'password' => 'initial-password']);
                $this->g[$key.$role] = $u;
                $m = $a->memberships()->create(['user_id' => $u->id, 'role' => $role === 'owner' ? 'owner' : 'operator', 'status' => 'active']);
                $this->g[$key.$role.'m'] = $m;
                $m->branchAssignments()->create(['account_id' => $a->id, 'branch_id' => $this->g[$key.'branch']->id, 'is_active' => true]);
            }
        }
        $this->g['platform'] = User::factory()->create(['email' => 'platform@example.test', 'username' => 'platform', 'status' => 'active', 'password' => 'initial-password']);
        PlatformUserPrivilege::create(['user_id' => $this->g['platform']->id, 'role' => 'superuser', 'is_active' => true]);
    }

    private function url(string $key = 'a', ?AccountMembership $m = null): string
    {
        return '/api/v1/accounts/'.$this->g[$key]->id.'/members'.($m ? '/'.$m->id : '');
    }

    private function payload(array $extra = []): array
    {
        return array_replace(['name' => 'Fresh Person', 'email' => 'fresh@example.test', 'username' => 'fresh.person', 'password' => 'initial-password', 'role' => 'operator', 'branch_ids' => [$this->g['abranch']->id]], $extra);
    }

    public function test_fresh_and_exact_same_account_retry_do_not_mutate_global_identity(): void
    {
        $this->actingAs($this->g['aowner']);
        $first = $this->postJson($this->url(), $this->payload())->assertCreated()->json('data');
        $before = User::findOrFail($first['user_id'])->getRawOriginal();
        $this->postJson($this->url(), $this->payload())->assertCreated()->assertJsonPath('data.id', $first['id']);
        $this->assertSame($before, User::findOrFail($first['user_id'])->getRawOriginal());
        $this->assertDatabaseCount('operational_people', 0);
    }

    public static function collisions(): array
    {
        return [
            'A/C foreign email, new username' => [['email' => 'b.staff@example.test']],
            'B/D foreign username, new email' => [['username' => 'b.staff']],
            'E both identifiers same foreign identity' => [['email' => 'b.staff@example.test', 'username' => 'b.staff']],
            'F platform email' => [['email' => 'platform@example.test']],
            'F platform username' => [['username' => 'platform']],
            'different foreign identities' => [['email' => 'b.staff@example.test', 'username' => 'b.owner']],
        ];
    }

    #[DataProvider('collisions')]
    public function test_foreign_collisions_fail_neutrally(array $collision): void
    {
        $before = DB::table('users')->orderBy('id')->get()->toJson();
        $response = $this->actingAs($this->g['aowner'])->postJson($this->url(), $this->payload($collision));
        $response->assertConflict();
        $this->assertStringNotContainsString('Synthetic Tenant', $response->getContent());
        $this->assertStringNotContainsString($this->g['b']->id, $response->getContent());
        $this->assertSame($before, DB::table('users')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('account_memberships', 4);
    }

    public function test_case_normalized_collisions_are_also_denied(): void
    {
        $this->actingAs($this->g['aowner'])->postJson($this->url(), $this->payload(['email' => ' B.STAFF@example.test ']))->assertConflict();
        $this->postJson($this->url(), $this->payload(['username' => ' B.STAFF ']))->assertConflict();
    }

    public function test_non_owner_governance_is_denied(): void
    {
        foreach (['admin', 'technician', 'operator'] as $role) {
            $this->g['astaffm']->update(['role' => $role]);
            $this->actingAs($this->g['astaff'])->postJson($this->url(), $this->payload())->assertForbidden();
            $this->patchJson($this->url('a', $this->g['aownerm']), ['status' => 'suspended'])->assertForbidden();
        }
    }

    private function attach(User $user, string $account = 'a'): AccountMembership
    {
        $id = $this->actingAs($this->g['platform'])->postJson('/api/v1/platform/accounts/'.$this->g[$account]->id.'/members', ['user_id' => $user->id, 'role' => 'operator', 'branch_ids' => [$this->g[$account.'branch']->id]])->assertCreated()->json('data.id');

        return AccountMembership::findOrFail($id);
    }

    public function test_shared_identity_can_only_be_attached_explicitly_by_platform(): void
    {
        $this->actingAs($this->g['aowner'])->postJson('/api/v1/platform/accounts/'.$this->g['a']->id.'/members', ['user_id' => $this->g['bstaff']->id, 'role' => 'operator', 'branch_ids' => [$this->g['abranch']->id]])->assertForbidden();
        $m = $this->attach($this->g['bstaff']);
        $this->assertSame(2, $this->g['bstaff']->memberships()->count());
        $this->actingAs($this->g['aowner'])->patchJson($this->url('b', $this->g['bstaffm']), ['status' => 'revoked'])->assertForbidden();
        $this->patchJson($this->url('a', $m), ['status' => 'revoked'])->assertOk();
        $this->assertSame('active', $this->g['bstaffm']->fresh()->status);
        $this->assertSame('active', $this->g['bstaff']->fresh()->status);
        $this->actingAs($this->g['bowner'])->patchJson($this->url('a', $m), ['status' => 'active'])->assertForbidden();
    }

    public function test_owner_cannot_mutate_any_managed_global_credentials(): void
    {
        // Baseline reproduction deliberately uses synthetic trusted fixture attachment.
        $m = $this->g['a']->memberships()->create(['user_id' => $this->g['bstaff']->id, 'role' => 'operator', 'status' => 'active']);
        foreach ([$m, $this->g['astaffm']] as $target) {
            $before = $target->user->getRawOriginal();
            $this->actingAs($this->g['aowner'])->patchJson($this->url('a', $target).'/email', ['email' => 'stolen@example.test'])->assertForbidden();
            $this->patchJson($this->url('a', $target), ['username' => 'stolen', 'status' => 'suspended'])->assertForbidden();
            $this->postJson($this->url('a', $target).'/password', ['password' => 'stolen-password', 'password_confirmation' => 'stolen-password'])->assertForbidden();
            $this->assertSame($before, $target->user->fresh()->getRawOriginal());
            $this->assertSame('active', $target->fresh()->status);
        }
    }

    public function test_platform_identity_cannot_be_tenant_managed_even_when_already_attached(): void
    {
        $m = $this->g['a']->memberships()->create(['user_id' => $this->g['platform']->id, 'role' => 'operator', 'status' => 'active']);
        $this->actingAs($this->g['aowner'])->patchJson($this->url('a', $m), ['status' => 'suspended'])->assertForbidden();
        $this->patchJson($this->url('a', $m), ['username' => 'stolen'])->assertForbidden();
        $this->patchJson($this->url('a', $m).'/email', ['email' => 'stolen@example.test'])->assertForbidden();
        $this->postJson($this->url('a', $m).'/password', ['password' => 'stolen-password', 'password_confirmation' => 'stolen-password'])->assertForbidden();
        $this->assertSame('active', $this->g['platform']->fresh()->status);
    }

    public function test_foreign_and_nonexistent_branches_cannot_partially_mutate_membership(): void
    {
        $m = $this->g['astaffm'];
        foreach ([$this->g['bbranch']->id, '00000000-0000-4000-8000-000000000099'] as $branch) {
            $before = $m->fresh()->getRawOriginal();
            $assignments = $m->branchAssignments()->orderBy('id')->get()->toJson();
            $this->actingAs($this->g['aowner'])->patchJson($this->url('a', $m), ['role' => 'admin', 'status' => 'suspended', 'branch_ids' => [$branch]])->assertUnprocessable();
            $this->assertSame($before, $m->fresh()->getRawOriginal());
            $this->assertSame($assignments, $m->branchAssignments()->orderBy('id')->get()->toJson());
        }
    }

    public function test_last_owner_demote_suspend_revoke_are_denied_and_two_owners_allow_one_transition(): void
    {
        $this->actingAs($this->g['platform']);
        foreach ([['role' => 'admin'], ['status' => 'suspended'], ['status' => 'revoked']] as $change) {
            $this->patchJson($this->url('a', $this->g['aownerm']), $change)->assertConflict();
        }
        $this->patchJson($this->url('a', $this->g['astaffm']), ['role' => 'owner'])->assertOk();
        $this->patchJson($this->url('a', $this->g['aownerm']), ['role' => 'operator'])->assertOk();
        $this->patchJson($this->url('a', $this->g['astaffm']), ['status' => 'revoked'])->assertConflict();
    }

    public function test_password_reset_invalidates_old_target_session_but_preserves_admin_session(): void
    {
        $target = $this->g['astaff'];
        $this->postJson('/api/v1/auth/login', ['login' => $target->email, 'password' => 'initial-password'])->assertOk();
        $old = session()->all();
        $this->postJson('/api/v1/auth/login', ['login' => $this->g['platform']->email, 'password' => 'initial-password'])->assertOk();
        $this->postJson($this->url('a', $this->g['astaffm']).'/password', ['password' => 'replacement-password', 'password_confirmation' => 'replacement-password'])->assertOk();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.user.id', $this->g['platform']->id);
        session()->flush();
        $this->withSession($old);
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['login' => $target->email, 'password' => 'replacement-password'])->assertOk();
        $this->getJson('/api/v1/me')->assertOk();
    }

    public function test_global_disable_and_membership_suspend_have_distinct_next_request_effects(): void
    {
        $m = $this->attach($this->g['bstaff']);
        $this->actingAs($this->g['aowner'])->patchJson($this->url('a', $m), ['status' => 'suspended'])->assertOk();
        $this->actingAs($this->g['bstaff'])->getJson('/api/v1/accounts/'.$this->g['a']->id.'/branches')->assertForbidden();
        $this->getJson('/api/v1/accounts/'.$this->g['b']->id.'/branches')->assertOk();
        $this->g['bstaff']->update(['status' => 'disabled']);
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_persistence_failure_rolls_back_role_profile_and_assignments(): void
    {
        $m = $this->g['astaffm'];
        $before = $m->fresh()->getRawOriginal();
        $user = $m->user->getRawOriginal();
        $branches = $m->branchAssignments()->get()->toJson();
        $this->mock(GovernanceAudit::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Synthetic persistence failure'));
        $this->actingAs($this->g['platform'])->patchJson($this->url('a', $m), ['role' => 'admin', 'status' => 'suspended', 'username' => 'new.username', 'branch_ids' => []])->assertStatus(500);
        $this->assertSame($before, $m->fresh()->getRawOriginal());
        $this->assertSame($user, $m->user->fresh()->getRawOriginal());
        $this->assertSame($branches, $m->branchAssignments()->get()->toJson());
    }

    public function test_operational_person_creation_does_not_create_login_or_membership(): void
    {
        $before = User::count();
        $this->actingAs($this->g['platform'])->postJson('/api/v1/accounts/'.$this->g['a']->id.'/operational-people', ['name' => 'Synthetic PIC'])->assertCreated();
        $this->assertSame($before, User::count());
        $this->assertDatabaseCount('account_memberships', 4);
        $this->assertDatabaseHas('operational_people', ['name' => 'Synthetic PIC', 'linked_user_id' => null]);
    }

    public function test_retry_cannot_change_identity_role_branches_or_reactivate_revoked_membership(): void
    {
        $this->actingAs($this->g['aowner']);
        $first = $this->postJson($this->url(), $this->payload())->assertCreated()->json('data');
        $user = User::findOrFail($first['user_id']);
        $before = $user->getRawOriginal();
        $this->postJson($this->url(), $this->payload(['name' => 'Changed', 'password' => 'changed-password']))->assertCreated();
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->postJson($this->url(), $this->payload(['role' => 'admin']))->assertConflict();
        $m = AccountMembership::findOrFail($first['id']);
        $m->update(['status' => 'revoked']);
        $this->postJson($this->url(), $this->payload())->assertConflict();
        $this->assertSame('revoked', $m->fresh()->status);
    }

    public function test_platform_can_manage_shared_credentials_and_self_identity(): void
    {
        $m = $this->attach($this->g['bstaff']);
        $this->patchJson($this->url('a', $m), ['username' => 'SHARED.RENAMED', 'display_name' => 'Shared Person'])->assertOk();
        $this->patchJson($this->url('a', $m).'/email', ['email' => ' SHARED@example.test '])->assertOk();
        $this->assertSame('shared.renamed', $this->g['bstaff']->fresh()->username);
        $this->assertSame('shared@example.test', $this->g['bstaff']->fresh()->email);
        $this->patchJson('/api/v1/me/account', ['action' => 'profile', 'displayName' => 'Platform Person', 'username' => 'platform.self'])->assertOk();
    }

    public function test_owners_cannot_disable_global_identity_or_promote_to_owner(): void
    {
        $this->actingAs($this->g['aowner'])->patchJson($this->url('a', $this->g['astaffm']), ['status' => 'disabled'])->assertUnprocessable();
        $this->patchJson($this->url('a', $this->g['astaffm']), ['role' => 'owner'])->assertForbidden();
        $this->assertSame('active', $this->g['astaff']->fresh()->status);
        $this->assertFalse(app(PlatformPrivilegeService::class)->isSuperuser($this->g['aowner']));
    }

    public function test_file_session_cookie_replay_is_rejected_after_reset(): void
    {
        config(['session.driver' => 'file']);
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $login = $this->postJson('/api/v1/auth/login', ['login' => $this->g['astaff']->email, 'password' => 'initial-password'])->assertOk();
        $cookie = $login->getCookie(config('session.cookie'))->getValue();
        $path = config('session.files').'/'.$cookie;
        try {
            $payload = unserialize(file_get_contents($path));
            $this->assertSame(0, $payload['identity_session_version']);
            $this->g['astaff']->update(['password' => 'replacement-password']);
            app('auth')->forgetGuards();
            $this->withCookie(config('session.cookie'), $cookie)->getJson('/api/v1/me')->assertUnauthorized();
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_legacy_session_and_reenabled_identity_cannot_bypass_revocation(): void
    {
        $target = $this->g['astaff'];
        $this->postJson('/api/v1/auth/login', ['login' => $target->email, 'password' => 'initial-password'])->assertOk();
        session()->forget('identity_session_version');
        $this->getJson('/api/v1/me')->assertOk();
        $target->update(['status' => 'disabled']);
        $target->update(['status' => 'active']);
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_malformed_identity_values_are_validation_errors_without_writes(): void
    {
        foreach (['email', 'username'] as $field) {
            $this->actingAs($this->g['aowner'])->postJson($this->url(), $this->payload([$field => ['bad']]))->assertUnprocessable();
        }
        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('account_memberships', 4);
    }
}
