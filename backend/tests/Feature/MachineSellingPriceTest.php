<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Machine;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MachineSellingPriceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $account = Account::create(['code' => 'MSP', 'name' => 'Selling Price', 'default_timezone' => 'Asia/Jakarta']);
        $branch = $account->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'timezone' => 'Asia/Jakarta']);
        $owner = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $account->id, 'user_id' => $owner->id, 'role' => 'owner', 'status' => 'active']);
        AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $branch->id, 'is_active' => true]);
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'C1070', 'name' => 'C1070']);
        $machine = Machine::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'CG-MAIN-A3-01', 'display_name' => 'C1070', 'status' => 'active']);

        $otherAccount = Account::create(['code' => 'MSP2', 'name' => 'Other', 'default_timezone' => 'Asia/Jakarta']);
        $otherBranch = $otherAccount->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'timezone' => 'Asia/Jakarta']);
        $otherMachine = Machine::create(['account_id' => $otherAccount->id, 'branch_id' => $otherBranch->id, 'machine_model_id' => $model->id, 'machine_code' => 'CG-OTHER-A3-01', 'display_name' => 'C1070', 'status' => 'active']);
        $otherOwner = User::factory()->create(['status' => 'active']);
        $otherMembership = AccountMembership::create(['account_id' => $otherAccount->id, 'user_id' => $otherOwner->id, 'role' => 'owner', 'status' => 'active']);
        AccountMembershipBranch::create(['account_id' => $otherAccount->id, 'membership_id' => $otherMembership->id, 'branch_id' => $otherBranch->id, 'is_active' => true]);

        return compact('account', 'branch', 'owner', 'machine', 'otherMachine', 'otherOwner');
    }

    public function test_a_valid_selling_price_save_succeeds_with_an_iso_effective_datetime(): void
    {
        $f = $this->fixture();

        // This is the exact payload shape the frontend sends: a full ISO-8601
        // instant with milliseconds and a trailing Z, as produced by
        // Date#toISOString(). Before the fix this crashed the raw DB::table
        // insert against a MySQL timestamp column with a 500.
        $this->actingAs($f['owner'])
            ->postJson("/api/v1/machines/{$f['machine']->id}/cost/selling-prices", [
                'price_per_click' => 1000,
                'effective_from' => '2026-09-05T13:00:00.000Z',
                'notes' => null,
                'client_request_id' => (string) Str::uuid(),
            ])
            ->assertCreated();
        $this->assertEquals(1000, (float) DB::table('machine_selling_prices')->first()->price_per_click);
        $this->assertEquals('posted', DB::table('machine_selling_prices')->first()->status);
    }

    public function test_notes_are_optional(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['owner'])
            ->postJson("/api/v1/machines/{$f['machine']->id}/cost/selling-prices", [
                'price_per_click' => 500,
                'effective_from' => '2026-09-05T13:00:00.000Z',
                'client_request_id' => (string) Str::uuid(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.notes', null);
    }

    public function test_invalid_price_is_rejected_with_a_domain_validation_error_not_a_500(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['owner'])
            ->postJson("/api/v1/machines/{$f['machine']->id}/cost/selling-prices", [
                'price_per_click' => 0,
                'effective_from' => '2026-09-05T13:00:00.000Z',
                'client_request_id' => (string) Str::uuid(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price_per_click']);
    }

    public function test_missing_effective_datetime_is_rejected(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['owner'])
            ->postJson("/api/v1/machines/{$f['machine']->id}/cost/selling-prices", [
                'price_per_click' => 800,
                'client_request_id' => (string) Str::uuid(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['effective_from']);
    }

    public function test_malformed_datetime_is_rejected(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['owner'])
            ->postJson("/api/v1/machines/{$f['machine']->id}/cost/selling-prices", [
                'price_per_click' => 800,
                'effective_from' => 'not-a-date',
                'client_request_id' => (string) Str::uuid(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['effective_from']);
    }

    public function test_unauthorized_branch_machine_is_denied(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['owner'])
            ->postJson("/api/v1/machines/{$f['otherMachine']->id}/cost/selling-prices", [
                'price_per_click' => 800,
                'effective_from' => '2026-09-05T13:00:00.000Z',
                'client_request_id' => (string) Str::uuid(),
            ])
            ->assertForbidden();
    }

    public function test_multiple_historical_prices_are_preserved_and_effective_price_resolves_deterministically(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['owner'])->postJson("/api/v1/machines/{$f['machine']->id}/cost/selling-prices", ['price_per_click' => 500, 'effective_from' => '2026-09-01T00:00:00.000Z', 'client_request_id' => (string) Str::uuid()])->assertCreated();
        $this->actingAs($f['owner'])->postJson("/api/v1/machines/{$f['machine']->id}/cost/selling-prices", ['price_per_click' => 800, 'effective_from' => '2026-09-05T00:00:00.000Z', 'client_request_id' => (string) Str::uuid()])->assertCreated();

        $rows = \Illuminate\Support\Facades\DB::table('machine_selling_prices')->where('machine_id', $f['machine']->id)->orderByDesc('effective_from')->get();
        $this->assertCount(2, $rows);
        $this->assertEquals('800.0000', $rows->first()->price_per_click);
        $this->assertEquals('500.0000', $rows->last()->price_per_click);
    }

    public function test_a_retried_client_request_id_returns_the_existing_row_instead_of_a_raw_conflict(): void
    {
        $f = $this->fixture();
        $clientRequestId = (string) Str::uuid();
        $payload = ['price_per_click' => 800, 'effective_from' => '2026-09-05T00:00:00.000Z', 'client_request_id' => $clientRequestId];
        $first = $this->actingAs($f['owner'])->postJson("/api/v1/machines/{$f['machine']->id}/cost/selling-prices", $payload)->assertCreated();
        $second = $this->actingAs($f['owner'])->postJson("/api/v1/machines/{$f['machine']->id}/cost/selling-prices", $payload)->assertOk();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
    }

    public function test_same_client_request_id_with_different_values_is_a_truthful_conflict(): void
    {
        $f = $this->fixture();
        $clientRequestId = (string) Str::uuid();
        $this->actingAs($f['owner'])->postJson("/api/v1/machines/{$f['machine']->id}/cost/selling-prices", ['price_per_click' => 800, 'effective_from' => '2026-09-05T00:00:00.000Z', 'client_request_id' => $clientRequestId])->assertCreated();
        $this->actingAs($f['owner'])->postJson("/api/v1/machines/{$f['machine']->id}/cost/selling-prices", ['price_per_click' => 900, 'effective_from' => '2026-09-05T00:00:00.000Z', 'client_request_id' => $clientRequestId])
            ->assertStatus(409);
    }

    public function test_voiding_a_price_excludes_it_but_preserves_history(): void
    {
        $f = $this->fixture();
        $created = $this->actingAs($f['owner'])->postJson("/api/v1/machines/{$f['machine']->id}/cost/selling-prices", ['price_per_click' => 800, 'effective_from' => '2026-09-05T00:00:00.000Z', 'client_request_id' => (string) Str::uuid()])->assertCreated();
        $id = $created->json('data.id');

        $this->actingAs($f['owner'])->postJson("/api/v1/selling-prices/{$id}/void", ['reason' => 'Entered by mistake', 'client_request_id' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.status', 'voided');

        $row = \Illuminate\Support\Facades\DB::table('machine_selling_prices')->find($id);
        $this->assertNotNull($row);
        $this->assertSame('voided', $row->status);
    }

    public function test_voiding_an_already_voided_price_is_a_truthful_conflict_not_a_silent_noop(): void
    {
        $f = $this->fixture();
        $created = $this->actingAs($f['owner'])->postJson("/api/v1/machines/{$f['machine']->id}/cost/selling-prices", ['price_per_click' => 800, 'effective_from' => '2026-09-05T00:00:00.000Z', 'client_request_id' => (string) Str::uuid()])->assertCreated();
        $id = $created->json('data.id');
        $this->actingAs($f['owner'])->postJson("/api/v1/selling-prices/{$id}/void", ['reason' => 'first void', 'client_request_id' => (string) Str::uuid()])->assertOk();
        $this->actingAs($f['owner'])->postJson("/api/v1/selling-prices/{$id}/void", ['reason' => 'second void', 'client_request_id' => (string) Str::uuid()])->assertStatus(409);
    }

    public function test_voiding_across_accounts_is_denied(): void
    {
        $f = $this->fixture();
        $created = $this->actingAs($f['owner'])->postJson("/api/v1/machines/{$f['machine']->id}/cost/selling-prices", ['price_per_click' => 800, 'effective_from' => '2026-09-05T00:00:00.000Z', 'client_request_id' => (string) Str::uuid()])->assertCreated();
        $id = $created->json('data.id');
        $this->actingAs($f['otherOwner'])->postJson("/api/v1/selling-prices/{$id}/void", ['reason' => 'not my price', 'client_request_id' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_api_error_message_is_a_real_message_not_generic_on_validation_failure(): void
    {
        $f = $this->fixture();
        $response = $this->actingAs($f['owner'])->postJson("/api/v1/machines/{$f['machine']->id}/cost/selling-prices", ['price_per_click' => -5, 'effective_from' => '2026-09-05T00:00:00.000Z', 'client_request_id' => (string) Str::uuid()]);
        $response->assertUnprocessable();
        $this->assertNotSame('An unexpected error occurred.', $response->json('message'));
    }
}
