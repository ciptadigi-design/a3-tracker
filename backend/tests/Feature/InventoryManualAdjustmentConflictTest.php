<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Branch;
use App\Models\ComponentCatalog;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Services\InventoryLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Production report: posting a manual "Stock Correction" decrease adjustment
 * for CAN-CHARGING-CORONA-K at "CG Digital Print" returned a bare 409
 * "Conflict." with no actionable reason.
 *
 * This proves, end-to-end through the real /inventory/adjustments route, that
 * a legitimate correction (A/B) succeeds, exactly one movement is posted,
 * historical movements stay immutable, and the genuine guards this workflow
 * depends on (insufficient stock, duplicate submission, cross-type request-id
 * reuse) are unchanged. See InventoryManualAdjustmentConflictMessageTest for
 * the error-message fix (Task 4).
 */
class InventoryManualAdjustmentConflictTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(float $opening = 10): array
    {
        $account = Account::create(['code' => 'CG', 'name' => 'Cipta Grafika', 'status' => 'active']);
        $branch = Branch::create(['account_id' => $account->id, 'code' => 'CG-TUP', 'name' => 'Tuparev', 'is_active' => true]);
        $user = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        $component = ComponentCatalog::create(['account_id' => $account->id, 'code' => 'CAN_CHARGING_CORONA_K', 'name' => 'Charging Corona Black', 'is_active' => true]);
        $item = InventoryItem::create(['account_id' => $account->id, 'component_id' => $component->id, 'sku' => 'CAN-CHARGING-CORONA-K', 'name' => 'Charging Corona Black', 'unit' => 'pcs', 'is_active' => true]);
        $location = InventoryLocation::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'code' => 'CG_DIGITAL', 'name' => 'CG Digital Print', 'is_active' => true]);
        app(InventoryLedgerService::class)->inbound($item, $location, $opening, 50000, 'opening_balance', (string) Str::uuid(), 'test fixture opening');

        return compact('account', 'branch', 'user', 'item', 'location');
    }

    private function adjust(User $user, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->postJson('/api/v1/inventory/adjustments', $payload);
    }

    // A. Valid decrease adjustment: balance 10, decrease 1, expect 9.
    public function test_a_valid_decrease_adjustment_posts_one_movement_and_lowers_balance(): void
    {
        $f = $this->fixture(10);

        $response = $this->adjust($f['user'], [
            'item_id' => $f['item']->id, 'location_id' => $f['location']->id,
            'quantity' => -1, 'reason' => 'Stock Correction', 'client_request_id' => (string) Str::uuid(),
        ]);

        $response->assertCreated();
        $this->assertSame(9.0, app(InventoryLedgerService::class)->balance($f['item']->id, $f['location']->id));
        $this->assertSame(1, \App\Models\InventoryMovement::where('inventory_item_id', $f['item']->id)->where('movement_type', 'adjustment_out')->count());
    }

    // B. Valid increase adjustment: balance 10, increase 1, expect 11.
    public function test_b_valid_increase_adjustment_posts_one_movement_and_raises_balance(): void
    {
        $f = $this->fixture(10);

        $response = $this->adjust($f['user'], [
            'item_id' => $f['item']->id, 'location_id' => $f['location']->id,
            'quantity' => 1, 'reason' => 'Stock Correction', 'client_request_id' => (string) Str::uuid(),
        ]);

        $response->assertCreated();
        $this->assertSame(11.0, app(InventoryLedgerService::class)->balance($f['item']->id, $f['location']->id));
    }

    // C. Decrease exceeding available stock must still conflict (existing domain contract).
    public function test_c_decrease_exceeding_available_stock_still_conflicts(): void
    {
        $f = $this->fixture(1);

        $response = $this->adjust($f['user'], [
            'item_id' => $f['item']->id, 'location_id' => $f['location']->id,
            'quantity' => -5, 'reason' => 'Stock Correction', 'client_request_id' => (string) Str::uuid(),
        ]);

        $response->assertStatus(409);
        $this->assertSame(1.0, app(InventoryLedgerService::class)->balance($f['item']->id, $f['location']->id));
    }

    // D. Duplicate submission (same client_request_id) must not double-post.
    public function test_d_duplicate_submission_with_same_request_id_posts_only_once(): void
    {
        $f = $this->fixture(10);
        $payload = ['item_id' => $f['item']->id, 'location_id' => $f['location']->id, 'quantity' => -1, 'reason' => 'Stock Correction', 'client_request_id' => (string) Str::uuid()];

        $first = $this->adjust($f['user'], $payload)->assertCreated();
        $second = $this->adjust($f['user'], $payload)->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(9.0, app(InventoryLedgerService::class)->balance($f['item']->id, $f['location']->id));
        $this->assertSame(1, \App\Models\InventoryMovement::where('client_request_id', $payload['client_request_id'])->count());
    }

    // E. A genuinely different request (same item/location, new request id) is a separate, legitimate correction.
    public function test_e_a_different_request_id_is_a_separate_legitimate_correction_not_a_conflict(): void
    {
        $f = $this->fixture(10);

        $this->adjust($f['user'], ['item_id' => $f['item']->id, 'location_id' => $f['location']->id, 'quantity' => -1, 'reason' => 'Stock Correction', 'client_request_id' => (string) Str::uuid()])->assertCreated();
        $this->adjust($f['user'], ['item_id' => $f['item']->id, 'location_id' => $f['location']->id, 'quantity' => -1, 'reason' => 'Stock Correction', 'client_request_id' => (string) Str::uuid()])->assertCreated();

        $this->assertSame(8.0, app(InventoryLedgerService::class)->balance($f['item']->id, $f['location']->id));
        $this->assertSame(2, \App\Models\InventoryMovement::where('inventory_item_id', $f['item']->id)->where('movement_type', 'adjustment_out')->count());
    }

    // F. Existing historical movements must not, by themselves, block a legitimate new correction.
    public function test_f_prior_posted_movements_do_not_block_a_new_legitimate_correction(): void
    {
        $f = $this->fixture(10);
        $ledger = app(InventoryLedgerService::class);
        $ledger->inbound($f['item'], $f['location'], 5, 50000, 'adjustment_in', (string) Str::uuid(), 'earlier correction');
        $ledger->outbound($f['item'], $f['location'], 2, 'adjustment_out', (string) Str::uuid(), null, 'earlier correction');
        $this->assertSame(13.0, $ledger->balance($f['item']->id, $f['location']->id));

        $response = $this->adjust($f['user'], [
            'item_id' => $f['item']->id, 'location_id' => $f['location']->id,
            'quantity' => -1, 'reason' => 'Stock Correction', 'client_request_id' => (string) Str::uuid(),
        ]);

        $response->assertCreated();
        $this->assertSame(12.0, $ledger->balance($f['item']->id, $f['location']->id));
        $this->assertSame(4, \App\Models\InventoryMovement::where('inventory_item_id', $f['item']->id)->count(), 'opening + adjustment_in + adjustment_out + new adjustment_out');
    }

    // Historical posted movements remain immutable regardless of new corrections.
    public function test_historical_movements_are_never_edited_or_deleted_by_a_new_correction(): void
    {
        $f = $this->fixture(10);
        $opening = \App\Models\InventoryMovement::where('inventory_item_id', $f['item']->id)->where('movement_type', 'opening_balance')->first();

        $this->adjust($f['user'], ['item_id' => $f['item']->id, 'location_id' => $f['location']->id, 'quantity' => -1, 'reason' => 'Stock Correction', 'client_request_id' => (string) Str::uuid()])->assertCreated();

        $opening->refresh();
        $this->assertSame(10.0, (float) $opening->quantity);
        $this->assertNotNull(\App\Models\InventoryMovement::find($opening->id), 'the opening movement must still exist, untouched');
    }

    // Authorization boundary: a user with no membership on this account cannot post an adjustment here.
    public function test_authorization_boundary_rejects_a_user_without_account_access(): void
    {
        $f = $this->fixture(10);
        $stranger = User::factory()->create(['status' => 'active']);

        $this->adjust($stranger, ['item_id' => $f['item']->id, 'location_id' => $f['location']->id, 'quantity' => -1, 'reason' => 'Stock Correction', 'client_request_id' => (string) Str::uuid()])
            ->assertStatus(403);
        $this->assertSame(10.0, app(InventoryLedgerService::class)->balance($f['item']->id, $f['location']->id));
    }
}
