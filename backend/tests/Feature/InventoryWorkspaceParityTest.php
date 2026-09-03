<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Branch;
use App\Models\ComponentCatalog;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\OperationalPerson;
use App\Models\OperationalPersonBranch;
use App\Models\User;
use App\Services\InventoryLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryWorkspaceParityTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $account = Account::create(['code' => 'CG', 'name' => 'Cipta Grafika', 'status' => 'active']);
        $tuparev = Branch::create(['account_id' => $account->id, 'code' => 'CG-TUP', 'name' => 'Tuparev', 'is_active' => true]);
        $graha = Branch::create(['account_id' => $account->id, 'code' => 'CG-GRH', 'name' => 'Graha', 'is_active' => true]);
        $user = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);

        $component = ComponentCatalog::create(['account_id' => $account->id, 'code' => 'DRUM_K', 'name' => 'Drum Black', 'is_active' => true]);
        $item = InventoryItem::create(['account_id' => $account->id, 'component_id' => $component->id, 'sku' => 'DRUM-K', 'name' => 'Drum Black', 'unit' => 'pcs', 'is_active' => true]);
        $location = InventoryLocation::create(['account_id' => $account->id, 'branch_id' => $tuparev->id, 'code' => 'CG_DIGITAL', 'name' => 'CG Digital Print', 'is_active' => true]);
        $movement = app(InventoryLedgerService::class)->inbound($item, $location, 3, null, 'opening_balance', (string) Str::uuid(), 'test fixture');

        $person = OperationalPerson::create(['account_id' => $account->id, 'name' => 'Tuparev PIC', 'is_active' => true]);
        OperationalPersonBranch::create(['account_id' => $account->id, 'person_id' => $person->id, 'branch_id' => $tuparev->id, 'is_active' => true, 'can_record_counter' => true]);

        $purchaseId = (string) Str::uuid();
        DB::table('purchases')->insert(['id' => $purchaseId, 'account_id' => $account->id, 'branch_id' => $tuparev->id, 'purchase_number' => 'TUP-1', 'purchase_date' => '2026-09-01', 'currency_code' => 'IDR', 'status' => 'draft', 'client_request_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('purchase_lines')->insert(['id' => (string) Str::uuid(), 'account_id' => $account->id, 'purchase_id' => $purchaseId, 'inventory_item_id' => $item->id, 'ordered_quantity' => 1, 'unit_cost' => null, 'created_at' => now(), 'updated_at' => now()]);

        $otherAccount = Account::create(['code' => 'OTHER', 'name' => 'Other', 'status' => 'active']);
        $otherComponent = ComponentCatalog::create(['account_id' => $otherAccount->id, 'code' => 'PRIVATE', 'name' => 'Other Account Component', 'is_active' => true]);

        return compact('account', 'tuparev', 'graha', 'user', 'component', 'item', 'location', 'movement', 'person', 'purchaseId', 'otherComponent');
    }

    public function test_tuparev_existing_state_and_canonical_component_relationship_return_200(): void
    {
        $f = $this->fixture();

        $this->actingAs($f['user'])->getJson("/api/v1/accounts/{$f['account']->id}/branches/{$f['tuparev']->id}/inventory")
            ->assertOk()
            ->assertJsonPath('data.branchId', $f['tuparev']->id)
            ->assertJsonPath('data.items.0.component.id', (string) $f['component']->id)
            ->assertJsonPath('data.locations.0.id', (string) $f['location']->id)
            ->assertJsonPath('data.balances.0.quantity', 3)
            ->assertJsonPath('data.purchases.0.id', $f['purchaseId'])
            ->assertJsonPath('data.people.0.id', (string) $f['person']->id);

        $this->assertTrue($f['item']->component()->is($f['component']));
    }

    public function test_graha_without_inventory_configuration_returns_a_real_empty_physical_state(): void
    {
        $f = $this->fixture();
        $before = ['locations' => InventoryLocation::count(), 'movements' => InventoryMovement::count()];

        $response = $this->actingAs($f['user'])->getJson("/api/v1/accounts/{$f['account']->id}/branches/{$f['graha']->id}/inventory")
            ->assertOk()
            ->assertJsonPath('data.branchId', $f['graha']->id)
            ->assertJsonCount(0, 'data.locations')
            ->assertJsonCount(0, 'data.balances')
            ->assertJsonCount(0, 'data.movements')
            ->assertJsonCount(0, 'data.purchases')
            ->assertJsonCount(0, 'data.purchaseLines')
            ->assertJsonCount(0, 'data.receipts')
            ->assertJsonCount(0, 'data.people');

        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame(0, $response->json('data.totals.0.quantity'));
        $this->assertSame($before['locations'], InventoryLocation::count());
        $this->assertSame($before['movements'], InventoryMovement::count());
    }

    public function test_inventory_workspace_preserves_account_and_branch_isolation(): void
    {
        $f = $this->fixture();
        $response = $this->actingAs($f['user'])->getJson("/api/v1/accounts/{$f['account']->id}/branches/{$f['graha']->id}/inventory")->assertOk();

        $response->assertJsonMissing(['id' => $f['location']->id])
            ->assertJsonMissing(['id' => $f['movement']->id])
            ->assertJsonMissing(['id' => $f['purchaseId']])
            ->assertJsonMissing(['id' => $f['person']->id])
            ->assertJsonMissing(['id' => $f['otherComponent']->id]);

        $otherAccount = Account::create(['code' => 'MISMATCH', 'name' => 'Mismatch', 'status' => 'active']);
        AccountMembership::create(['account_id' => $otherAccount->id, 'user_id' => $f['user']->id, 'role' => 'owner', 'status' => 'active']);
        $this->actingAs($f['user'])->getJson("/api/v1/accounts/{$otherAccount->id}/branches/{$f['graha']->id}/inventory")->assertNotFound();
    }
}
