<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\Machine;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\User;
use App\Services\OverviewPurchaseSummaryService;
use App\Services\PurchaseSummaryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OverviewPurchaseSummaryTest extends TestCase
{
    use RefreshDatabase;

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $account = Account::create(['code' => 'PUR-KPI', 'name' => 'Purchase KPI', 'status' => 'active']);
        $branch = Branch::create(['account_id' => $account->id, 'code' => 'MAIN', 'name' => 'Main', 'is_active' => true]);
        $otherBranch = Branch::create(['account_id' => $account->id, 'code' => 'OTHER', 'name' => 'Other', 'is_active' => true]);
        $user = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $branch->id, 'is_active' => true]);
        $manufacturer = Manufacturer::create(['code' => 'KPI', 'name' => 'KPI Manufacturer']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'KPI', 'name' => 'KPI']);
        $machineA = Machine::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'KPI-1', 'display_name' => 'KPI 1', 'status' => 'active']);
        $machineB = Machine::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'KPI-2', 'display_name' => 'KPI 2', 'status' => 'active']);
        $item = InventoryItem::create(['account_id' => $account->id, 'sku' => 'ITEM-1', 'name' => 'Item 1', 'unit' => 'pcs', 'is_active' => true]);
        $location = InventoryLocation::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'code' => 'STORE', 'name' => 'Store', 'is_active' => true]);
        $this->fixture = compact('account', 'branch', 'otherBranch', 'user', 'machineA', 'machineB', 'item', 'location');
    }

    private function purchase(string $date, float $quantity, ?float $unitCost, array $overrides = []): array
    {
        $f = $this->fixture;
        $purchaseId = (string) Str::uuid();
        $lineId = (string) Str::uuid();
        DB::table('purchases')->insert([
            'id' => $purchaseId,
            'account_id' => $overrides['account_id'] ?? $f['account']->id,
            'branch_id' => $overrides['branch_id'] ?? $f['branch']->id,
            'purchase_number' => $overrides['purchase_number'] ?? 'PUR-'.Str::random(8),
            'purchase_date' => $date,
            'currency_code' => 'IDR',
            'status' => $overrides['status'] ?? 'draft',
            'notes' => $overrides['notes'] ?? null,
            'client_request_id' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('purchase_lines')->insert([
            'id' => $lineId,
            'account_id' => $overrides['account_id'] ?? $f['account']->id,
            'purchase_id' => $purchaseId,
            'inventory_item_id' => $overrides['inventory_item_id'] ?? $f['item']->id,
            'ordered_quantity' => $quantity,
            'unit_cost' => $unitCost,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('purchaseId', 'lineId');
    }

    private function receive(array $purchase, float $quantity): void
    {
        $f = $this->fixture;
        $receiptId = (string) Str::uuid();
        DB::table('receipts')->insert(['id' => $receiptId, 'account_id' => $f['account']->id, 'purchase_id' => $purchase['purchaseId'], 'location_id' => $f['location']->id, 'received_at' => '2026-10-10 10:00:00', 'client_request_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('receipt_lines')->insert(['id' => (string) Str::uuid(), 'account_id' => $f['account']->id, 'receipt_id' => $receiptId, 'purchase_line_id' => $purchase['lineId'], 'inventory_item_id' => $f['item']->id, 'quantity' => $quantity, 'unit_cost' => DB::table('purchase_lines')->where('id', $purchase['lineId'])->value('unit_cost'), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_selected_purchase_period_accumulates_commercial_value_and_partial_receipts(): void
    {
        $first = $this->purchase('2026-09-04', 10, 1000000);
        $second = $this->purchase('2026-09-20', 2, 500000, ['status' => 'received']);
        $this->receive($first, 6);
        $this->receive($second, 2);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $summary = app(OverviewPurchaseSummaryService::class)->forBranchPeriod($this->fixture['account']->id, $this->fixture['branch']->id, '2026-09-01', '2026-09-30');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame('11000000.00', $summary['purchase_value']);
        $this->assertSame(2, $summary['purchase_count']);
        $this->assertSame('7000000.00', $summary['received_value']);
        $this->assertSame(63.64, $summary['received_percentage']);
        $this->assertSame('branch', $summary['scope']);
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('sum(', strtolower($queries[0]['query']));
    }

    public function test_zero_and_complete_receiving_are_safe_and_exact(): void
    {
        $zero = app(OverviewPurchaseSummaryService::class)->forBranchPeriod($this->fixture['account']->id, $this->fixture['branch']->id, '2026-08-01', '2026-08-31');
        $this->assertSame(['purchase_value' => '0.00', 'purchase_count' => 0, 'received_value' => '0.00', 'received_percentage' => 0.0, 'scope' => 'branch'], $zero);

        $purchase = $this->purchase('2026-09-10', 4, 250000, ['status' => 'received']);
        $this->receive($purchase, 4);
        $complete = app(OverviewPurchaseSummaryService::class)->forBranchPeriod($this->fixture['account']->id, $this->fixture['branch']->id, '2026-09-01', '2026-09-30');
        $this->assertSame('1000000.00', $complete['received_value']);
        $this->assertSame(100.0, $complete['received_percentage']);
    }

    public function test_period_state_legacy_account_and_branch_filters_are_enforced(): void
    {
        $included = $this->purchase('2026-09-05', 2, 100);
        $this->purchase('2026-08-31', 99, 100);
        $this->purchase('2026-09-06', 99, 100, ['status' => 'cancelled']);
        $this->purchase('2026-09-07', 99, 100, ['purchase_number' => 'LEGACY-1', 'notes' => 'LEGACY_IMPORT; RECEIPT_UNKNOWN_NOT_REPRESENTED']);
        $this->purchase('2026-09-08', 99, 100, ['branch_id' => $this->fixture['otherBranch']->id]);

        $otherAccount = Account::create(['code' => 'PRIVATE', 'name' => 'Private', 'status' => 'active']);
        $otherItem = InventoryItem::create(['account_id' => $otherAccount->id, 'sku' => 'PRIVATE', 'name' => 'Private', 'is_active' => true]);
        $otherBranch = Branch::create(['account_id' => $otherAccount->id, 'code' => 'PRIVATE', 'name' => 'Private', 'is_active' => true]);
        $this->purchase('2026-09-09', 99, 100, ['account_id' => $otherAccount->id, 'branch_id' => $otherBranch->id, 'inventory_item_id' => $otherItem->id]);

        $summary = app(OverviewPurchaseSummaryService::class)->forBranchPeriod($this->fixture['account']->id, $this->fixture['branch']->id, '2026-09-01', '2026-09-30');
        $this->assertSame('200.00', $summary['purchase_value']);
        $this->assertSame(1, $summary['purchase_count']);
        $this->assertSame('0.00', $summary['received_value']);
        $this->assertSame(0.0, $summary['received_percentage']);
        $this->assertNotEmpty($included);
    }

    public function test_machine_selector_does_not_change_branch_purchase_summary_or_existing_machine_metrics(): void
    {
        $this->purchase('2026-09-12', 3, 500);
        $query = '?period_start=2026-09-01&period_end=2026-09-30&summary_only=1';
        $first = $this->actingAs($this->fixture['user'])->getJson('/api/v1/machines/'.$this->fixture['machineA']->id.'/cost'.$query)->assertOk();
        $second = $this->actingAs($this->fixture['user'])->getJson('/api/v1/machines/'.$this->fixture['machineB']->id.'/cost'.$query)->assertOk();

        $this->assertSame($first->json('purchase_summary'), $second->json('purchase_summary'));
        $this->assertSame('1500.00', $first->json('purchase_summary.purchase_value'));
        $this->assertSame('0.00', $first->json('component_consumption_cost'));
        $this->assertSame($first->json('standard_cost_per_click'), $second->json('standard_cost_per_click'));
    }

    public function test_overview_value_reconciles_with_purchasing_authoritative_totals(): void
    {
        $this->purchase('2026-09-15', 3, 125000);
        $this->purchase('2026-09-16', 2, null);
        $purchases = DB::table('purchases')->where('account_id', $this->fixture['account']->id)->where('branch_id', $this->fixture['branch']->id)->get();
        $lines = DB::table('purchase_lines')->whereIn('purchase_id', $purchases->pluck('id'))->get();
        $purchasing = app(PurchaseSummaryBuilder::class)->build($purchases, $lines, collect(), collect([$this->fixture['item']]), collect());
        $overview = app(OverviewPurchaseSummaryService::class)->forBranchPeriod($this->fixture['account']->id, $this->fixture['branch']->id, '2026-09-01', '2026-09-30');

        $this->assertEquals((float) $purchasing['purchases']->sum('purchase_total'), (float) $overview['purchase_value']);
    }
}
