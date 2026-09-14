<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ComponentCatalog;
use App\Models\ComponentLifecycle;
use App\Models\CounterReading;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventorySupplier;
use App\Models\Machine;
use App\Models\MachineComponent;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\ModelProfile;
use App\Models\ModelProfileSlot;
use App\Models\OperationalIncident;
use App\Models\OperationalPerson;
use App\Models\OperationalPersonBranch;
use App\Models\PlatformUserPrivilege;
use App\Models\User;
use App\Services\CreateCounterReading;
use App\Services\InventoryLedgerService;
use App\Services\OperationalReportService;
use App\Services\PurchaseReceiptService;
use App\Services\ReplaceMachineComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class M2_20GControlledPilotAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private array $g = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->g['platform'] = User::factory()->create(['name' => 'PLATFORM_SUPERUSER', 'username' => 'pilot.platform', 'status' => 'active']);
        PlatformUserPrivilege::create(['user_id' => $this->g['platform']->id, 'role' => 'superuser', 'is_active' => true]);
        $globalManufacturer = Manufacturer::create(['code' => 'PILOT_GLOBAL', 'name' => 'Pilot shared manufacturer']);
        $this->g['globalModel'] = MachineModel::create(['manufacturer_id' => $globalManufacturer->id, 'model_code' => 'PILOT_SHARED', 'name' => 'Pilot shared model']);

        $this->makeTenant('cg', 'CG_ACCOUNT', 'Asia/Jakarta', 'Asia/Jakarta');
        $this->makeTenant('ext', 'EXTERNAL_ACCOUNT', 'Asia/Makassar', 'Asia/Jayapura');
    }

    private function makeTenant(string $key, string $name, string $accountTimezone, string $machineTimezone): void
    {
        $account = $this->g[$key] = Account::create(['code' => strtoupper($key).'_PILOT', 'name' => $name, 'default_timezone' => $accountTimezone, 'default_currency' => 'IDR', 'status' => 'active']);
        $branch1 = $this->g[$key.'Branch1'] = $account->branches()->create(['code' => 'B1', 'name' => strtoupper($key).'_BRANCH_1', 'timezone' => $accountTimezone, 'is_active' => true]);
        $this->g[$key.'Branch2'] = $account->branches()->create(['code' => 'B2', 'name' => strtoupper($key).'_BRANCH_2', 'timezone' => $key === 'ext' ? 'Asia/Makassar' : 'Asia/Jakarta', 'is_active' => true]);

        foreach (['owner', 'admin', 'technician', 'operator'] as $role) {
            $user = $this->g[$key.ucfirst($role)] = User::factory()->create(['name' => strtoupper($key).'_'.strtoupper($role), 'username' => $key.'.'.$role, 'status' => 'active']);
            $membership = $this->g[$key.ucfirst($role).'Membership'] = $account->memberships()->create(['user_id' => $user->id, 'role' => $role, 'status' => 'active', 'accepted_at' => now()]);
            if ($role !== 'owner') {
                $membership->branchAssignments()->create(['account_id' => $account->id, 'branch_id' => $branch1->id, 'is_active' => true]);
            }
        }

        $manufacturer = $this->g[$key.'Manufacturer'] = Manufacturer::create(['account_id' => $account->id, 'code' => strtoupper($key).'_PRIVATE', 'name' => $name.' private manufacturer']);
        $model = $this->g[$key.'Model'] = MachineModel::create(['account_id' => $account->id, 'manufacturer_id' => $manufacturer->id, 'model_code' => strtoupper($key).'_MODEL', 'name' => $name.' private model']);
        $component = $this->g[$key.'Component'] = ComponentCatalog::create(['account_id' => $account->id, 'code' => strtoupper($key).'_DRUM', 'name' => $name.' private drum']);
        $profile = ModelProfile::create(['account_id' => $account->id, 'machine_model_id' => $model->id, 'name' => $name.' profile']);
        $slot = ModelProfileSlot::create(['profile_id' => $profile->id, 'component_id' => $component->id, 'slot_code' => 'DRUM', 'baseline_expected_clicks' => 100000]);
        $machine = $this->g[$key.'Machine1'] = Machine::create(['account_id' => $account->id, 'branch_id' => $branch1->id, 'machine_model_id' => $model->id, 'machine_code' => strtoupper($key).'_MACHINE_1', 'display_name' => $name.' machine', 'timezone' => $machineTimezone, 'status' => 'active']);
        $machineComponent = $this->g[$key.'MachineComponent'] = MachineComponent::create(['account_id' => $account->id, 'machine_id' => $machine->id, 'component_id' => $component->id, 'profile_slot_id' => $slot->id, 'slot_code' => 'DRUM', 'source_type' => 'inherited', 'tracking_method' => 'counter_based', 'status' => 'configured', 'active_key' => 'active']);
        ComponentLifecycle::create(['machine_component_id' => $machineComponent->id, 'started_at' => '2026-09-01T00:00:00Z', 'status' => 'active', 'source' => 'manual', 'active_key' => 'active']);
        $this->g[$key.'Supplier'] = InventorySupplier::create(['account_id' => $account->id, 'code' => strtoupper($key).'_SUP', 'name' => $name.' private supplier']);
        $this->g[$key.'Item'] = InventoryItem::create(['account_id' => $account->id, 'component_id' => $component->id, 'sku' => strtoupper($key).'_ITEM', 'name' => $name.' private item', 'unit' => 'pcs']);
        $this->g[$key.'Location'] = InventoryLocation::create(['account_id' => $account->id, 'branch_id' => $branch1->id, 'code' => 'WH1', 'name' => $name.' warehouse']);
        $person = $this->g[$key.'Person'] = OperationalPerson::create(['account_id' => $account->id, 'code' => strtoupper($key).'_PIC', 'name' => $name.' operator', 'is_active' => true]);
        OperationalPersonBranch::create(['account_id' => $account->id, 'person_id' => $person->id, 'branch_id' => $branch1->id, 'can_record_counter' => true, 'is_active' => true]);
    }

    public function test_named_two_tenant_fixture_is_bidirectionally_isolated_by_account_and_branch(): void
    {
        $this->actingAs($this->g['cgOwner'])->getJson('/api/v1/accounts/'.$this->g['cg']->id.'/settings')->assertOk();
        $this->getJson('/api/v1/accounts/'.$this->g['ext']->id.'/settings')->assertForbidden();
        $this->getJson('/api/v1/branches/'.$this->g['extBranch1']->id.'/machines')->assertForbidden();
        $this->getJson('/api/v1/machines/'.$this->g['extMachine1']->id)->assertForbidden();

        $this->actingAs($this->g['extOwner'])->getJson('/api/v1/accounts/'.$this->g['ext']->id.'/settings')->assertOk();
        $this->getJson('/api/v1/accounts/'.$this->g['cg']->id.'/settings')->assertForbidden();
        $this->getJson('/api/v1/accounts/'.$this->g['cg']->id.'/audit')->assertForbidden();

        $this->actingAs($this->g['extOperator']);
        $this->getJson('/api/v1/branches/'.$this->g['extBranch1']->id.'/machines')->assertOk();
        $this->getJson('/api/v1/branches/'.$this->g['extBranch2']->id.'/machines')->assertForbidden();
        $this->getJson('/api/v1/accounts/'.$this->g['ext']->id.'/branches/'.$this->g['extBranch2']->id.'/inventory')->assertForbidden();
        $this->getJson('/api/v1/accounts/'.$this->g['ext']->id.'/branches/'.$this->g['extBranch2']->id.'/incidents')->assertForbidden();
        $this->getJson('/api/v1/reports?account_id='.$this->g['ext']->id.'&branch_id='.$this->g['extBranch2']->id.'&period_start=2026-09-01&period_end=2026-09-30')->assertForbidden();
    }

    public function test_fresh_external_owner_completes_routine_setup_without_platform_dependency(): void
    {
        $account = $this->actingAs($this->g['platform'])->postJson('/api/v1/accounts', ['code' => 'EXT_FRESH', 'name' => 'Fresh External Printer', 'default_timezone' => 'Asia/Makassar', 'default_currency' => 'IDR'])->assertCreated()->json('data');
        $owner = User::factory()->create(['name' => 'Fresh External Owner', 'email' => 'fresh.owner@example.test', 'username' => 'fresh.external.owner', 'password' => Hash::make('synthetic-password'), 'status' => 'active']);
        $this->postJson('/api/v1/platform/accounts/'.$account['id'].'/members', ['user_id' => $owner->id, 'role' => 'owner', 'branch_ids' => []])->assertCreated();
        $unscopedOperator = User::factory()->create(['status' => 'active']);
        $this->postJson('/api/v1/platform/accounts/'.$account['id'].'/members', ['user_id' => $unscopedOperator->id, 'role' => 'operator', 'branch_ids' => []])->assertUnprocessable()->assertJsonValidationErrors('branch_ids');

        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->postJson('/api/v1/auth/login', ['login' => 'fresh.external.owner', 'password' => 'synthetic-password'])->assertOk();
        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.platform.is_superuser', false);
        $this->getJson('/api/v1/accounts/'.$account['id'].'/branches')->assertOk()->assertJsonCount(0, 'data.data');
        $this->getJson('/api/v1/accounts/'.$account['id'].'/settings')->assertOk()->assertJsonCount(0, 'data.branches');
        $branch = $this->postJson('/api/v1/accounts/'.$account['id'].'/branches', ['code' => 'FIRST', 'name' => 'First Branch', 'timezone' => 'Asia/Makassar'])->assertCreated()->json('data');
        $person = $this->postJson('/api/v1/accounts/'.$account['id'].'/operational-people', ['code' => 'PIC1', 'name' => 'First PIC'])->assertCreated()->json('data');
        $this->putJson('/api/v1/operational-people/'.$person['id'].'/branches', ['assignments' => [['branch_id' => $branch['id'], 'can_record_counter' => true]]])->assertOk();

        foreach (['admin', 'technician', 'operator'] as $role) {
            $this->postJson('/api/v1/accounts/'.$account['id'].'/members', ['name' => ucfirst($role), 'email' => "fresh.$role@example.test", 'username' => "fresh.external.$role", 'password' => 'synthetic-password', 'role' => $role, 'branch_ids' => [$branch['id']]])->assertCreated();
        }
        $this->patchJson('/api/v1/accounts/'.$account['id'].'/settings/policy', ['operator_can_log_errors' => true, 'operator_can_create_purchase' => false])->assertOk();
        $machine = $this->postJson('/api/v1/branches/'.$branch['id'].'/machines', ['machine_model_id' => $this->g['globalModel']->id, 'machine_code' => 'EXT-001', 'display_name' => 'External Press', 'timezone' => 'Asia/Jayapura'])->assertCreated()->json('data');
        $this->postJson('/api/v1/inventory/suppliers', ['account_id' => $account['id'], 'code' => 'SUP1', 'name' => 'Pilot Supplier'])->assertOk();
        $this->postJson('/api/v1/inventory/items', ['account_id' => $account['id'], 'sku' => 'PAPER', 'name' => 'Pilot Paper', 'unit' => 'ream'])->assertOk();
        $this->postJson('/api/v1/inventory/locations', ['account_id' => $account['id'], 'branch_id' => $branch['id'], 'code' => 'WH', 'name' => 'Warehouse'])->assertOk();
        $this->putJson('/api/v1/machines/'.$machine['id'].'/click-target', ['target_year' => 2026, 'target_month' => 9, 'monthly_click_target' => 100000, 'client_request_id' => (string) Str::uuid()])->assertCreated();
        $this->getJson('/api/v1/accounts/'.$account['id'].'/audit')->assertOk()->assertJsonFragment(['action' => 'membership.created'])->assertJsonFragment(['action' => 'machine.created']);

        $operator = User::where('username', 'fresh.external.operator')->firstOrFail();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->postJson('/api/v1/auth/login', ['login' => 'fresh.external.operator', 'password' => 'synthetic-password'])->assertOk();
        $caps = $this->getJson('/api/v1/me')->assertOk()->json('data.capabilities.'.$account['id']);
        $this->assertTrue($caps['incidents.create']);
        $this->assertFalse($caps['inventory.purchase.create']);
        $this->assertFalse($caps['settings.view']);
        $this->getJson('/api/v1/accounts/'.$account['id'].'/settings')->assertForbidden();
        $this->postJson('/api/v1/platform/bootstrap-superuser', ['user_id' => $operator->id])->assertForbidden();
    }

    public function test_operational_evidence_reconciles_all_reports_and_tenant_export_is_secret_free(): void
    {
        $account = $this->g['ext'];
        $branch = $this->g['extBranch1'];
        $machine = $this->g['extMachine1'];
        $item = $this->g['extItem'];
        $location = $this->g['extLocation'];
        $owner = $this->g['extOwner'];
        $operator = $this->g['extOperator'];
        $person = $this->g['extPerson'];

        app(InventoryLedgerService::class)->inbound($item, $location, 10, 100, 'opening_balance', (string) Str::uuid(), null, '2026-09-01T00:00:00Z');
        $purchase = app(PurchaseReceiptService::class)->purchase($account->id, ['branch_id' => $branch->id, 'supplier_id' => $this->g['extSupplier']->id, 'purchase_number' => 'EXT-PUR-1', 'purchase_date' => '2026-09-14', 'currency_code' => 'IDR', 'client_request_id' => (string) Str::uuid(), 'lines' => [['inventory_item_id' => $item->id, 'quantity' => 3, 'unit_cost' => 110]]]);
        $purchaseLine = DB::table('purchase_lines')->where('purchase_id', $purchase->id)->first();
        app(PurchaseReceiptService::class)->receive($purchase->id, $location, [['purchase_line_id' => $purchaseLine->id, 'quantity' => 3]], (string) Str::uuid(), $person->id, $person->name, $owner->id, '2026-09-13T15:10:00Z');
        app(CreateCounterReading::class)->execute($operator, $machine, ['reading_value' => 1000, 'observed_at' => '2026-09-13T15:20:00Z', 'operator_person_id' => $person->id, 'client_request_id' => (string) Str::uuid()]);
        app(CreateCounterReading::class)->execute($operator, $machine, ['reading_value' => 1200, 'observed_at' => '2026-09-13T15:30:00Z', 'operator_person_id' => $person->id, 'client_request_id' => (string) Str::uuid()]);
        OperationalIncident::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_id' => $machine->id, 'occurred_at' => '2026-09-13T15:35:00Z', 'category' => 'kualitas', 'incident_type' => 'waste', 'description' => 'Synthetic pilot incident', 'material_loss' => 50, 'service_loss' => 0, 'penalty_multiplier' => 1, 'status' => 'open', 'client_request_id' => (string) Str::uuid()]);
        app(ReplaceMachineComponent::class)->execute($this->g['extMachineComponent'], ['inventory_source' => 'inventory', 'inventory_item_id' => $item->id, 'inventory_location_id' => $location->id, 'quantity' => 2, 'replaced_at' => '2026-09-13T15:40:00Z', 'client_request_id' => (string) Str::uuid(), 'entered_by' => $owner->id]);
        $this->actingAs($owner)->postJson('/api/v1/machines/'.$machine->id.'/cost/operating-costs', ['category' => 'other', 'amount' => 500, 'allocation_method' => 'one_time', 'description' => 'Synthetic one-time cost', 'effective_at' => '2026-09-13T15:45:00Z', 'client_request_id' => (string) Str::uuid()])->assertCreated();
        $this->patchJson('/api/v1/accounts/'.$account->id.'/profile', ['name' => 'EXTERNAL_ACCOUNT', 'default_timezone' => 'Asia/Makassar'])->assertOk();

        $report = app(OperationalReportService::class)->build($account->id, $branch->id, $machine->id, '2026-09-14', '2026-09-14', null, null, [$branch->id]);
        foreach (['overview', 'counter', 'machine_cost', 'replacements', 'incidents', 'operator_activity', 'inventory_consumption'] as $section) {
            $this->assertArrayHasKey($section, $report, $section);
        }
        $this->assertCount(1, $report['inventory_consumption']);
        $this->assertSame('Asia/Jayapura', $report['inventory_consumption'][0]['resolved_timezone']);
        $this->assertSame('2026-09-14', $report['inventory_consumption'][0]['operational_date']);
        $this->assertSame('200.00', $report['inventory_consumption'][0]['consumed_cost']);
        $this->assertSame(200.0, (float) $report['overview']['total_clicks']);

        $manifest = $this->tenantExportManifest($account);
        $encoded = json_encode($manifest, JSON_THROW_ON_ERROR);
        foreach (['account', 'branches', 'machines', 'tenant_owned_config', 'memberships', 'operational_people', 'counters', 'incidents', 'purchases', 'inventory', 'fifo', 'replacements', 'targets', 'audit'] as $section) {
            $this->assertArrayHasKey($section, $manifest);
        }
        $this->assertStringNotContainsString((string) $this->g['cg']->id, $encoded);
        $this->assertStringNotContainsString((string) $this->g['cgMachine1']->id, $encoded);
        $this->assertStringNotContainsString((string) $this->g['cgOwner']->password, $encoded);
        foreach (['password', 'remember_token', 'session_version', 'token'] as $secret) {
            $this->assertArrayNotHasKey($secret, $manifest['memberships'][0]);
        }
    }

    public function test_membership_revocation_is_account_local_and_last_owner_is_protected(): void
    {
        $operator = $this->g['extOperator'];
        $cgMembership = $this->g['cg']->memberships()->create(['user_id' => $operator->id, 'role' => 'operator', 'status' => 'active', 'accepted_at' => now()]);
        $cgMembership->branchAssignments()->create(['account_id' => $this->g['cg']->id, 'branch_id' => $this->g['cgBranch1']->id, 'is_active' => true]);

        $this->actingAs($this->g['extOwner'])->patchJson('/api/v1/accounts/'.$this->g['ext']->id.'/members/'.$this->g['extOperatorMembership']->id, ['status' => 'revoked', 'branch_ids' => [$this->g['extBranch1']->id]])->assertOk();
        $accounts = $this->actingAs($operator)->getJson('/api/v1/me')->assertOk()->json('data.accounts');
        $this->assertCount(1, $accounts);
        $this->assertSame((string) $this->g['cg']->id, (string) $accounts[0]['id']);

        $this->actingAs($this->g['extOwner'])->patchJson('/api/v1/accounts/'.$this->g['ext']->id.'/members/'.$this->g['extOwnerMembership']->id, ['status' => 'revoked'])->assertConflict();
        $this->assertSame('active', $this->g['extOwnerMembership']->fresh()->status);
    }

    private function tenantExportManifest(Account $account): array
    {
        $id = $account->id;
        $memberships = DB::table('account_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.account_id', $id)->select(['m.user_id', 'm.role', 'm.status', 'm.accepted_at', 'u.name', 'u.email', 'u.username'])->get()->map(fn ($row) => (array) $row)->all();

        return [
            'account' => $account->only(['id', 'code', 'name', 'default_timezone', 'default_currency', 'status']),
            'branches' => DB::table('branches')->where('account_id', $id)->get()->all(),
            'machines' => DB::table('machines')->where('account_id', $id)->get()->all(),
            'tenant_owned_config' => [
                'manufacturers' => DB::table('manufacturers')->where('account_id', $id)->get()->all(),
                'models' => DB::table('machine_models')->where('account_id', $id)->get()->all(),
                'components' => DB::table('component_catalogs')->where('account_id', $id)->get()->all(),
                'profiles' => DB::table('model_profiles')->where('account_id', $id)->get()->all(),
            ],
            'memberships' => $memberships,
            'operational_people' => DB::table('operational_people')->where('account_id', $id)->get()->all(),
            'counters' => CounterReading::where('account_id', $id)->get()->all(),
            'incidents' => OperationalIncident::where('account_id', $id)->get()->all(),
            'purchases' => DB::table('purchases')->where('account_id', $id)->get()->all(),
            'inventory' => [
                'items' => DB::table('inventory_items')->where('account_id', $id)->get()->all(),
                'locations' => DB::table('inventory_locations')->where('account_id', $id)->get()->all(),
                'movements' => DB::table('inventory_movements')->where('account_id', $id)->get()->all(),
            ],
            'fifo' => [
                'layers' => DB::table('fifo_layers')->where('account_id', $id)->get()->all(),
                'allocations' => DB::table('fifo_allocations')->where('account_id', $id)->get()->all(),
            ],
            'replacements' => DB::table('component_replacements')->where('account_id', $id)->get()->all(),
            'targets' => DB::table('machine_click_targets')->where('account_id', $id)->get()->all(),
            'audit' => DB::table('governance_audit_logs')->where('account_id', $id)->get()->all(),
        ];
    }
}
