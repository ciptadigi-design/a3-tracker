<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MyAccountParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_profile_email_and_password_require_authorized_credentials(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);
        $this->postJson('/api/v1/auth/login', ['identifier' => $user->email, 'password' => 'old-password'])->assertOk();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->patchJson('/api/v1/me/account', ['action' => 'profile', 'displayName' => 'Updated Name', 'username' => 'updated-user'])->assertOk();
        $this->patchJson('/api/v1/me/account', ['action' => 'email', 'email' => 'updated@example.test', 'currentPassword' => 'wrong'])->assertStatus(422);
        $this->patchJson('/api/v1/me/account', ['action' => 'email', 'email' => 'updated@example.test', 'currentPassword' => 'old-password'])->assertOk();
        $this->patchJson('/api/v1/me/account', ['action' => 'password', 'currentPassword' => 'wrong', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])->assertStatus(422);
        $this->patchJson('/api/v1/me/account', ['action' => 'password', 'currentPassword' => 'old-password', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])->assertOk();
    }

    public function test_account_endpoint_is_unauthenticated_safe_and_profile_cannot_change_governance(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $user = User::factory()->create(['password' => 'secret-password']);
        $this->postJson('/api/v1/auth/login', ['identifier' => $user->email, 'password' => 'secret-password'])->assertOk();
        $this->patchJson('/api/v1/me/account', ['action' => 'profile', 'displayName' => 'Name', 'username' => 'safe-user', 'role' => 'Owner', 'is_superuser' => true])->assertOk();
        $this->assertSame('Name', $user->fresh()->name);
    }

    public function test_username_uniqueness_and_cross_user_profile_boundary_are_explicit(): void
    {
        $userA = User::factory()->create(['username' => 'usera', 'password' => 'secret-password']);
        $userB = User::factory()->create(['username' => 'userb', 'email' => 'userb@example.test', 'password' => 'user-b-password']);
        $account = Account::create(['code' => 'ACCT', 'name' => 'Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $branch = $account->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_active' => true]);
        $membership = AccountMembership::create(['account_id' => $account->id, 'user_id' => $userA->id, 'role' => 'owner', 'status' => 'active', 'accepted_at' => now()]);
        AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $branch->id, 'is_active' => true]);

        $this->postJson('/api/v1/auth/login', ['identifier' => $userA->email, 'password' => 'secret-password'])->assertOk();
        $this->patchJson('/api/v1/me/account', ['action' => 'profile', 'displayName' => 'A', 'username' => 'userb'])->assertUnprocessable();
        $this->patchJson('/api/v1/me/account', ['action' => 'email', 'email' => $userB->email, 'currentPassword' => 'secret-password'])->assertUnprocessable();
        $this->patchJson('/api/v1/me/account', ['action' => 'password', 'currentPassword' => 'secret-password', 'password' => 'new-a-password', 'password_confirmation' => 'new-a-password'])->assertOk();

        $this->assertSame('usera', $userA->fresh()->username);
        $this->assertSame('userb', $userB->fresh()->username);
        $this->assertTrue(Hash::check('user-b-password', $userB->fresh()->password));
        $this->assertSame('owner', $membership->fresh()->role);
        $this->assertSame('active', $membership->fresh()->status);
        $this->assertSame($branch->id, $membership->branchAssignments()->first()->branch_id);
    }
}
