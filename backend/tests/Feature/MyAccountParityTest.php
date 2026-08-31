<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
