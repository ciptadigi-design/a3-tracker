<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Branch;
use App\Models\ComponentCatalog;
use App\Models\ComponentLifecycle;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\Machine;
use App\Models\MachineComponent;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\ModelProfile;
use App\Models\ModelProfileSlot;
use App\Models\PlatformUserPrivilege;
use App\Models\User;
use App\Services\InventoryLedgerService;
use App\Services\MachineTimezoneResolver;
use App\Services\OperationalReportService;
use App\Services\ReplaceMachineComponent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class M2_20FTenantOperationalTimeTest extends TestCase
{
    use RefreshDatabase;

    private function platformUser(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        PlatformUserPrivilege::create(['user_id' => $user->id, 'role' => 'superuser', 'is_active' => true]);

        return $user;
    }

    public static function validTimezones(): array
    {
        return array_map(fn ($timezone) => [$timezone], ['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura', 'America/New_York']);
    }

    #[DataProvider('validTimezones')]
    public function test_account_accepts_valid_iana_timezones(string $timezone): void
    {
        $this->actingAs($this->platformUser())->postJson('/api/v1/accounts', [
            'code' => 'TZ'.Str::random(8), 'name' => $timezone, 'default_timezone' => $timezone, 'default_currency' => 'IDR',
        ])->assertCreated()->assertJsonPath('data.default_timezone', $timezone);
    }

    public function test_account_rejects_invalid_timezone_and_unsupported_currency(): void
    {
        $user = $this->platformUser();
        $this->actingAs($user)->postJson('/api/v1/accounts', ['code' => 'BADTZ', 'name' => 'Bad TZ', 'default_timezone' => 'Invalid/NotATimezone', 'default_currency' => 'IDR'])->assertUnprocessable()->assertJsonValidationErrors('default_timezone');
        $this->actingAs($user)->postJson('/api/v1/accounts', ['code' => 'USD', 'name' => 'USD', 'default_timezone' => 'Asia/Jakarta', 'default_currency' => 'USD'])->assertUnprocessable()->assertJsonValidationErrors('default_currency');
    }

    public function test_branch_and_machine_reject_invalid_timezones(): void
    {
        $account = Account::create(['code' => 'SCOPE', 'name' => 'Scope', 'default_timezone' => 'Asia/Jakarta', 'default_currency' => 'IDR']);
        $user = $this->platformUser();
        AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        $this->actingAs($user)->postJson("/api/v1/accounts/{$account->id}/branches", ['code' => 'BAD', 'name' => 'Bad', 'timezone' => 'Invalid/NotATimezone'])->assertUnprocessable()->assertJsonValidationErrors('timezone');

        $branch = $account->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'timezone' => 'Asia/Makassar']);
        $manufacturer = Manufacturer::create(['code' => 'TZM', 'name' => 'Maker']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'TZM', 'name' => 'Model']);
        $this->actingAs($user)->postJson("/api/v1/branches/{$branch->id}/machines", ['machine_model_id' => $model->id, 'machine_code' => 'BAD-TZ', 'display_name' => 'Bad', 'timezone' => 'Invalid/NotATimezone'])->assertUnprocessable()->assertJsonValidationErrors('timezone');
    }

    public function test_machine_timezone_resolution_order_and_local_day_ranges(): void
    {
        [$account, $branch, , $machine] = $this->fixture();
        $resolver = app(MachineTimezoneResolver::class);
        $this->assertSame('Asia/Jayapura', $resolver->resolve($machine));
        [$start, $end] = $resolver->range($machine, '2026-09-14', '2026-09-14');
        $this->assertSame('2026-09-13T15:00:00+00:00', $start->toIso8601String());
        $this->assertSame('2026-09-14T15:00:00+00:00', $end->toIso8601String());

        $machine->update(['timezone' => null]);
        $this->assertSame('Asia/Makassar', $resolver->resolve($machine->fresh()));
        $branch->update(['timezone' => null]);
        $this->assertSame('Asia/Jakarta', $resolver->resolve($machine->fresh()));
        $account->update(['default_timezone' => 'UTC']);
        $this->assertSame('UTC', $resolver->resolve($machine->fresh()));
    }

    public function test_purchase_accepts_idr_and_rejects_unsupported_currency(): void
    {
        [$account, $branch, $user, , , $item] = $this->fixture();
        $payload = ['account_id' => $account->id, 'branch_id' => $branch->id, 'purchase_number' => 'IDR-1', 'purchase_date' => '2026-09-14', 'currency_code' => 'IDR', 'client_request_id' => (string) Str::uuid(), 'lines' => [['inventory_item_id' => $item->id, 'quantity' => 1, 'unit_cost' => 100]]];
        $this->actingAs($user)->postJson('/api/v1/purchases', $payload)->assertCreated()->assertJsonPath('data.currency_code', 'IDR');
        $this->postJson('/api/v1/purchases', array_replace($payload, ['purchase_number' => 'USD-1', 'currency_code' => 'USD', 'client_request_id' => (string) Str::uuid()]))->assertUnprocessable()->assertJsonValidationErrors('currency_code');
    }

    public function test_real_replacement_consumption_uses_replacement_time_and_excludes_other_movements(): void
    {
        [$account, $branch, , $machine, $component, $item, $location, $machineComponent] = $this->fixture();
        app(InventoryLedgerService::class)->inbound($item, $location, 5, 100, 'opening_balance', (string) Str::uuid(), null, '2026-09-01T00:00:00Z');
        CarbonImmutable::setTestNow('2026-10-01T00:00:00Z');
        $replacement = app(ReplaceMachineComponent::class)->execute($machineComponent, ['inventory_source' => 'inventory', 'inventory_item_id' => $item->id, 'inventory_location_id' => $location->id, 'quantity' => 2, 'replaced_at' => '2026-09-13T15:30:00Z', 'client_request_id' => (string) Str::uuid()]);
        app(InventoryLedgerService::class)->inbound($item, $location, 1, 100, 'receipt', (string) Str::uuid(), null, '2026-09-13T15:45:00Z');
        app(InventoryLedgerService::class)->outbound($item, $location, 1, 'adjustment_out', (string) Str::uuid(), null, 'manual count correction');
        app(InventoryLedgerService::class)->outbound($item, $location, 1, 'transfer_out', (string) Str::uuid(), null, 'warehouse transfer');
        CarbonImmutable::setTestNow();

        $report = app(OperationalReportService::class)->build($account->id, $branch->id, $machine->id, '2026-09-14', '2026-09-14', null, null, [$branch->id]);
        $this->assertCount(1, $report['inventory_consumption']);
        $this->assertSame((string) $replacement->id, (string) $report['inventory_consumption'][0]['replacement_id']);
        $this->assertSame((string) $machine->id, (string) $report['inventory_consumption'][0]['machine_id']);
        $this->assertSame('2026-09-14', $report['inventory_consumption'][0]['operational_date']);
        $this->assertSame(-2.0, $report['inventory_consumption'][0]['ledger_quantity']);
        $this->assertSame(2.0, $report['inventory_consumption'][0]['quantity_consumed']);
        $this->assertSame('200.00', $report['inventory_consumption'][0]['consumed_cost']);
    }

    private function fixture(): array
    {
        $account = Account::create(['code' => 'M220F'.Str::random(4), 'name' => 'M2.20F', 'default_timezone' => 'Asia/Jakarta', 'default_currency' => 'IDR']);
        $branch = Branch::create(['account_id' => $account->id, 'code' => 'EAST', 'name' => 'East', 'timezone' => 'Asia/Makassar']);
        $user = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
        AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $branch->id, 'is_active' => true]);
        $manufacturer = Manufacturer::create(['code' => 'M'.Str::random(5), 'name' => 'Maker']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'X'.Str::random(5), 'name' => 'Model']);
        $machine = Machine::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'M1', 'display_name' => 'M1', 'timezone' => 'Asia/Jayapura', 'status' => 'active']);
        $component = ComponentCatalog::create(['code' => 'DRUM'.Str::random(4), 'name' => 'Drum']);
        $profile = ModelProfile::create(['machine_model_id' => $model->id, 'name' => 'Profile']);
        $slot = ModelProfileSlot::create(['profile_id' => $profile->id, 'component_id' => $component->id, 'slot_code' => 'DRUM']);
        $machineComponent = MachineComponent::create(['account_id' => $account->id, 'machine_id' => $machine->id, 'component_id' => $component->id, 'profile_slot_id' => $slot->id, 'slot_code' => 'DRUM', 'source_type' => 'inherited', 'status' => 'configured', 'active_key' => 'active']);
        ComponentLifecycle::create(['machine_component_id' => $machineComponent->id, 'started_at' => '2026-09-01T00:00:00Z', 'status' => 'active', 'active_key' => 'active']);
        $item = InventoryItem::create(['account_id' => $account->id, 'component_id' => $component->id, 'sku' => 'DRUM-1', 'name' => 'Drum', 'unit' => 'pcs']);
        $location = InventoryLocation::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'code' => 'WH', 'name' => 'Warehouse']);

        return [$account, $branch, $user, $machine, $component, $item, $location, $machineComponent];
    }
}
