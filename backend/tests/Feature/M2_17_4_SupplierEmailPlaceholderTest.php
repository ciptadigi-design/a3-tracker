<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\InventorySupplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M2.17.4 Scope A8: legacy Supabase/AI-assisted migration rows used "-" as a
 * placeholder for "no email on file" in the optional Email field. Laravel's `email`
 * validation rule rejects that literally, so an Admin editing an otherwise-untouched
 * legacy supplier got "The email field must be a valid email address." on Save. The
 * fix must be field-aware: only Email gets placeholder normalization - Notes/Address
 * legitimately contain free text like "-" and must keep it verbatim.
 */
class M2_17_4_SupplierEmailPlaceholderTest extends TestCase
{
    use RefreshDatabase;

    private function adminWithAccount(): array
    {
        $account = Account::create(['code' => 'CG', 'name' => 'Cipta Grafika', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $user = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'admin', 'status' => 'active', 'accepted_at' => now()]);

        return [$account, $user];
    }

    public static function placeholderProvider(): array
    {
        return [['-'], ['--'], ['n/a'], ['N/A'], ['NA'], ['none'], ['None']];
    }

    /** @dataProvider placeholderProvider */
    public function test_legacy_email_placeholder_is_normalized_to_null_on_create(string $placeholder): void
    {
        [$account, $admin] = $this->adminWithAccount();

        $response = $this->actingAs($admin)->postJson('/api/v1/inventory/suppliers', [
            'account_id' => $account->id, 'code' => 'JFP', 'name' => 'Just_ForPrint', 'email' => $placeholder,
        ])->assertOk();

        $this->assertNull($response->json('data.email'));
    }

    /** @dataProvider placeholderProvider */
    public function test_legacy_email_placeholder_is_normalized_to_null_on_edit(string $placeholder): void
    {
        [$account, $admin] = $this->adminWithAccount();
        $supplier = InventorySupplier::create(['account_id' => $account->id, 'code' => 'JFP', 'name' => 'Just_ForPrint', 'email' => null]);

        $response = $this->actingAs($admin)->putJson("/api/v1/inventory/suppliers/{$supplier->id}", [
            'account_id' => $account->id, 'code' => 'JFP', 'name' => 'Just_ForPrint', 'email' => $placeholder,
        ])->assertOk();

        $this->assertNull($response->json('data.email'));
    }

    public function test_valid_email_still_saves_correctly(): void
    {
        [$account, $admin] = $this->adminWithAccount();

        $response = $this->actingAs($admin)->postJson('/api/v1/inventory/suppliers', [
            'account_id' => $account->id, 'code' => 'JFP', 'name' => 'Just_ForPrint', 'email' => 'dzikri@example.com',
        ])->assertOk();

        $this->assertSame('dzikri@example.com', $response->json('data.email'));
    }

    public function test_genuinely_malformed_email_is_still_rejected(): void
    {
        [$account, $admin] = $this->adminWithAccount();

        $this->actingAs($admin)->postJson('/api/v1/inventory/suppliers', [
            'account_id' => $account->id, 'code' => 'JFP', 'name' => 'Just_ForPrint', 'email' => 'not-an-email',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_omitted_and_empty_string_email_both_save_as_null(): void
    {
        [$account, $admin] = $this->adminWithAccount();

        $omitted = $this->actingAs($admin)->postJson('/api/v1/inventory/suppliers', [
            'account_id' => $account->id, 'code' => 'JFP', 'name' => 'Just_ForPrint',
        ])->assertOk();
        $this->assertNull($omitted->json('data.email'));

        $empty = $this->actingAs($admin)->postJson('/api/v1/inventory/suppliers', [
            'account_id' => $account->id, 'code' => 'JFP2', 'name' => 'Just_ForPrint 2', 'email' => '',
        ])->assertOk();
        $this->assertNull($empty->json('data.email'));
    }

    public function test_placeholder_normalization_is_field_aware_and_does_not_touch_notes_or_address(): void
    {
        [$account, $admin] = $this->adminWithAccount();

        $response = $this->actingAs($admin)->postJson('/api/v1/inventory/suppliers', [
            'account_id' => $account->id, 'code' => 'JFP', 'name' => 'Just_ForPrint',
            'email' => '-', 'notes' => '-', 'address' => '-',
        ])->assertOk();

        $this->assertNull($response->json('data.email'));
        $this->assertSame('-', $response->json('data.notes'));
        $this->assertSame('-', $response->json('data.address'));
    }
}
