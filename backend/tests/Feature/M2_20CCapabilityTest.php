<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ComponentCatalog;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventorySupplier;
use App\Models\Machine;
use App\Models\MachineComponent;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\ModelProfile;
use App\Models\OperationalPerson;
use App\Models\PlatformUserPrivilege;
use App\Models\User;
use App\Services\EffectiveCapabilityResolver;
use App\Services\InventoryLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class M2_20CCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private array $g = [];

    protected function setUp(): void
    {
        parent::setUp();
        $global = Manufacturer::create(['code' => 'GLOBAL', 'name' => 'Global', 'is_active' => true]);
        $this->g['globalManufacturer'] = $global;
        $this->g['globalComponent'] = ComponentCatalog::create(['code' => 'GLOBAL', 'name' => 'Global component', 'is_active' => true]);
        foreach (['a', 'b'] as $key) {
            $a = $this->g[$key] = Account::create(['code' => 'M220C_'.strtoupper($key), 'name' => 'Synthetic '.$key, 'status' => 'active']);
            $this->g[$key.'manufacturer'] = Manufacturer::create(['account_id' => $a->id, 'code' => strtoupper($key), 'name' => 'Private maker '.$key, 'is_active' => true]);
            $model = $this->g[$key.'model'] = MachineModel::create(['account_id' => $a->id, 'manufacturer_id' => $global->id, 'model_code' => strtoupper($key), 'name' => 'Private model '.$key, 'is_active' => true]);
            $component = $this->g[$key.'component'] = ComponentCatalog::create(['account_id' => $a->id, 'code' => strtoupper($key), 'name' => 'Private component '.$key, 'is_active' => true]);
            $this->g[$key.'profile'] = ModelProfile::create(['account_id' => $a->id, 'machine_model_id' => $model->id, 'name' => 'Profile', 'is_active' => true]);
            $this->g[$key.'item'] = InventoryItem::create(['account_id' => $a->id, 'sku' => strtoupper($key), 'name' => 'Item '.$key, 'unit' => 'pcs', 'is_active' => true]);
            $this->g[$key.'supplier'] = InventorySupplier::create(['account_id' => $a->id, 'code' => strtoupper($key), 'name' => 'Private supplier '.$key, 'is_active' => true]);
            $this->g[$key.'person'] = OperationalPerson::create(['account_id' => $a->id, 'name' => 'Private person '.$key, 'is_active' => true]);
            foreach ($key === 'a' ? [1, 2] : [1] as $n) {
                $branch = $this->g[$key.$n.'branch'] = $a->branches()->create(['code' => 'B'.$n, 'name' => 'Branch '.$key.$n, 'is_active' => true]);
                $machine = $this->g[$key.$n.'machine'] = Machine::create(['account_id' => $a->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => strtoupper($key).$n, 'display_name' => 'Machine '.$key.$n, 'status' => 'active']);
                $this->g[$key.$n.'mc'] = MachineComponent::create(['account_id' => $a->id, 'machine_id' => $machine->id, 'component_id' => $component->id, 'slot_code' => 'TEST', 'source_type' => 'manual', 'tracking_method' => 'counter_based', 'baseline_expected_clicks' => 1000, 'status' => 'configured']);
                $this->g[$key.$n.'location'] = InventoryLocation::create(['account_id' => $a->id, 'branch_id' => $branch->id, 'code' => 'L'.$n, 'name' => 'Location '.$key.$n, 'is_active' => true]);
            }
            foreach ($key === 'a' ? ['owner', 'admin', 'technician', 'operator'] : ['owner'] as $role) {
                $u = $this->g[$key.$role] = User::factory()->create(['status' => 'active']);
                $m = $a->memberships()->create(['user_id' => $u->id, 'role' => $role, 'status' => 'active']);
                if ($role !== 'owner') {
                    $m->branchAssignments()->create(['account_id' => $a->id, 'branch_id' => $this->g[$key.'1branch']->id, 'is_active' => true]);
                }
            }
        }
    }

    private const POLICIES = ['operator_can_initialize_component', 'operator_can_replace_component', 'operator_can_create_purchase', 'operator_can_receive_goods', 'operator_can_adjust_inventory', 'operator_can_transfer_inventory', 'operator_can_log_errors'];

    private function policy(bool $on): void
    {
        DB::table('account_operational_permissions')->updateOrInsert(['account_id' => $this->g['a']->id], array_fill_keys(self::POLICIES, $on));
    }

    private function hitAction(string $action, string $scope = 'a1')
    {
        $id = (string) Str::uuid();
        $base = ['client_request_id' => $id];
        $machine = $this->g[$scope.'machine'];
        $loc = $this->g[$scope.'location'];
        $item = $this->g[$scope[0].'item'];

        return match ($action) {
            'initialize' => $this->postJson('/api/v1/machine-components/'.$this->g[$scope.'mc']->id.'/lifecycles', $base),
            'incident' => $this->postJson('/api/v1/accounts/'.$this->g[$scope[0]]->id.'/branches/'.$this->g[$scope.'branch']->id.'/incidents', $base + ['occurred_at' => '2026-08-10', 'category' => 'error', 'incident_type' => 'test', 'description' => 'Synthetic']),
            'replace' => $this->postJson('/api/v1/machine-components/'.$this->g[$scope.'mc']->id.'/replacements', $base + ['inventory_source' => 'external_untracked', 'external_reason' => 'Synthetic']),
            'purchase' => $this->postJson('/api/v1/purchases', $base + ['account_id' => $this->g[$scope[0]]->id, 'branch_id' => $this->g[$scope.'branch']->id, 'purchase_number' => $id, 'purchase_date' => '2026-08-10', 'lines' => [['inventory_item_id' => $item->id, 'quantity' => 3, 'unit_cost' => 10]]]),
            'adjust' => $this->postJson('/api/v1/inventory/adjustments', $base + ['item_id' => $item->id, 'location_id' => $loc->id, 'quantity' => 1, 'reason' => 'Synthetic']),
            'transfer' => $this->postJson('/api/v1/inventory/transfers', $base + ['item_id' => $this->g['aitem']->id, 'from_location_id' => $this->g['a1location']->id, 'to_location_id' => $loc->id, 'quantity' => 1]),
            'price' => $this->postJson('/api/v1/machines/'.$machine->id.'/cost/selling-prices', $base + ['price_per_click' => 20, 'effective_from' => '2026-08-10']),
            'cost' => $this->postJson('/api/v1/machines/'.$machine->id.'/cost/operating-costs', $base + ['amount' => 20, 'category' => 'other', 'allocation_method' => 'direct', 'description' => 'Synthetic']),
        };
    }

    public static function operationalCases(): array
    {
        $cases = [];
        foreach (['initialize', 'incident', 'replace', 'purchase', 'adjust'] as $action) {
            foreach ([false, true] as $on) {
                $cases[$action.($on ? '_on' : '_off')] = [$action, $on];
            }
        }

        return $cases;
    }

    #[DataProvider('operationalCases')]
    public function test_operator_policy_direct_api(string $action, bool $on): void
    {
        $this->policy($on);
        $this->actingAs($this->g['aoperator']);
        $this->hitAction($action)->assertStatus($on ? 201 : 403);
    }

    public static function financialCases(): array
    {
        $cases = [];
        foreach (['owner', 'admin', 'technician', 'operator'] as $role) {
            foreach (['price', 'cost'] as $action) {
                $cases[$role.'_'.$action] = [$role, $action];
            }
        }

        return $cases;
    }

    #[DataProvider('financialCases')]
    public function test_financial_create_and_void(string $role, string $action): void
    {
        $this->policy(true);
        $allowed = in_array($role, ['owner', 'admin']);
        $this->actingAs($this->g['a'.$role]);
        $this->hitAction($action)->assertStatus($allowed ? 201 : 403);
        $this->actingAs($this->g['aowner']);
        $id = $this->hitAction($action)->assertCreated()->json('data.id');
        $this->actingAs($this->g['a'.$role])->postJson('/api/v1/'.($action === 'price' ? 'selling-prices' : 'operating-costs').'/'.$id.'/void', ['reason' => 'Synthetic', 'client_request_id' => (string) Str::uuid()])->assertStatus($allowed ? 200 : 403);
    }

    public function test_receiving_and_transfer_policy_and_scope(): void
    {
        $this->actingAs($this->g['aowner']);
        $p = $this->hitAction('purchase')->assertCreated()->json('data');
        $line = DB::table('purchase_lines')->where('purchase_id', $p['id'])->first();
        $payload = ['location_id' => $this->g['a1location']->id, 'client_request_id' => (string) Str::uuid(), 'lines' => [['purchase_line_id' => $line->id, 'quantity' => 2]]];
        $this->actingAs($this->g['aoperator']);
        $this->policy(false);
        $this->postJson('/api/v1/purchases/'.$p['id'].'/receive', $payload)->assertForbidden();
        $this->policy(true);
        $this->postJson('/api/v1/purchases/'.$p['id'].'/receive', $payload)->assertCreated();
        $to = InventoryLocation::create(['account_id' => $this->g['a']->id, 'branch_id' => $this->g['a1branch']->id, 'code' => 'L3', 'name' => 'Authorized destination', 'is_active' => true]);
        $payload = ['item_id' => $this->g['aitem']->id, 'from_location_id' => $this->g['a1location']->id, 'to_location_id' => $to->id, 'quantity' => 1, 'client_request_id' => (string) Str::uuid()];
        $this->policy(false);
        $this->postJson('/api/v1/inventory/transfers', $payload)->assertForbidden();
        $this->policy(true);
        $this->postJson('/api/v1/inventory/transfers', $payload)->assertCreated();
        $this->hitAction('transfer', 'a2')->assertForbidden();
        $this->hitAction('transfer', 'b1')->assertForbidden();
    }

    public function test_policy_cannot_bypass_resource_scope(): void
    {
        $this->policy(true);
        $this->actingAs($this->g['aoperator']);
        foreach (['a2', 'b1'] as $scope) {
            foreach (['initialize', 'incident', 'replace', 'purchase', 'adjust'] as $action) {
                $this->assertContains($this->hitAction($action, $scope)->status(), [403, 404, 422], $action.' '.$scope);
            }
        }
    }

    public function test_bootstrap_is_account_bound_and_inactive_membership_fails_closed(): void
    {
        $u = $this->g['aoperator'];
        $m = $this->g['b']->memberships()->create(['user_id' => $u->id, 'role' => 'owner', 'status' => 'active']);
        $this->policy(false);
        $data = $this->actingAs($u)->getJson('/api/v1/me')->assertOk()->json('data.capabilities');
        $this->assertIsArray($data);
        $this->assertFalse($data[$this->g['a']->id]['incidents.create']);
        $this->assertTrue($data[$this->g['b']->id]['incidents.create']);
        foreach (['suspended', 'revoked'] as $status) {
            $u->memberships()->where('account_id', $this->g['a']->id)->update(['status' => $status]);
            $data = $this->getJson('/api/v1/me')->assertOk()->json('data.capabilities');
            $this->assertArrayNotHasKey($this->g['a']->id, $data);
            $this->hitAction('initialize')->assertForbidden();
        }
    }

    public function test_selective_policy_does_not_grant_other_actions_or_governance(): void
    {
        $this->policy(false);
        DB::table('account_operational_permissions')->where('account_id', $this->g['a']->id)->update(['operator_can_log_errors' => true]);
        $this->actingAs($this->g['aoperator']);
        $this->hitAction('incident')->assertCreated();
        foreach (['initialize', 'replace', 'purchase', 'adjust', 'price', 'cost'] as $action) {
            $this->hitAction($action)->assertForbidden();
        }
        $this->patchJson('/api/v1/accounts/'.$this->g['a']->id.'/settings/policy', ['operator_can_create_purchase' => true])->assertForbidden();
    }

    public function test_platform_privilege_does_not_bypass_branch_or_machine_membership(): void
    {
        $u = User::factory()->create(['status' => 'active']);
        PlatformUserPrivilege::create(['user_id' => $u->id, 'role' => 'superuser', 'is_active' => true]);
        $resolver = app(EffectiveCapabilityResolver::class);
        $this->assertTrue($resolver->allows($u, $this->g['a'], 'components.lifecycle.initialize'));
        $this->actingAs($u);
        foreach (['initialize', 'replace', 'purchase', 'adjust', 'price', 'cost'] as $action) {
            $this->hitAction($action)->assertForbidden();
        }
        $this->assertContains($this->hitAction('incident')->status(), [403, 422]);
        $m = $this->g['a']->memberships()->create(['user_id' => $u->id, 'role' => 'admin', 'status' => 'active']);
        $m->branchAssignments()->create(['account_id' => $this->g['a']->id, 'branch_id' => $this->g['a1branch']->id, 'is_active' => true]);
        $this->hitAction('initialize')->assertCreated();
        $m->update(['status' => 'suspended']);
        $this->assertNotContains(true, $resolver->resolve($u, $this->g['a']));
    }

    public function test_inactive_account_and_membership_return_no_actionable_capabilities(): void
    {
        $resolver = app(EffectiveCapabilityResolver::class);
        $this->g['a']->update(['status' => 'suspended']);
        $this->assertNotContains(true, $resolver->resolve($this->g['aowner'], $this->g['a']));
        $this->actingAs($this->g['aowner'])->getJson('/api/v1/me')->assertOk()->assertJsonCount(0, 'data.accounts');
        $this->hitAction('initialize')->assertForbidden();
        $this->g['a']->update(['status' => 'active']);
        foreach (['invited', 'suspended', 'revoked'] as $status) {
            $this->g['aowner']->memberships()->update(['status' => $status]);
            $this->assertNotContains(true, $resolver->resolve($this->g['aowner'], $this->g['a']));
        }
    }

    public function test_inventory_replacement_policy_and_location_scope(): void
    {
        app(InventoryLedgerService::class)->inbound($this->g['aitem'], $this->g['a1location'], 10, 2, 'opening_balance', (string) Str::uuid());
        $this->actingAs($this->g['aoperator']);
        $url = '/api/v1/machine-components/'.$this->g['a1mc']->id.'/replacements';
        $d = ['client_request_id' => (string) Str::uuid(), 'inventory_source' => 'inventory', 'inventory_item_id' => $this->g['aitem']->id, 'inventory_location_id' => $this->g['a1location']->id, 'quantity' => 1];
        $this->policy(false);
        $this->postJson($url, $d)->assertForbidden();
        $this->policy(true);
        foreach (['a2', 'b1'] as $scope) {
            $this->postJson($url, array_replace($d, ['inventory_location_id' => $this->g[$scope.'location']->id]))->assertForbidden();
        }
        $this->postJson($url, $d)->assertCreated();
        $this->actingAs($this->g['atechnician'])->postJson($url, array_replace($d, ['client_request_id' => (string) Str::uuid()]))->assertCreated();
    }

    public function test_receiving_policy_cannot_bypass_destination_or_purchase_branch(): void
    {
        $this->actingAs($this->g['aowner']);
        $p = $this->hitAction('purchase', 'a2')->assertCreated()->json('data');
        $line = DB::table('purchase_lines')->where('purchase_id', $p['id'])->first();
        $this->policy(true);
        $this->actingAs($this->g['aoperator']);
        foreach (['a1', 'a2', 'b1'] as $scope) {
            $d = ['location_id' => $this->g[$scope.'location']->id, 'client_request_id' => (string) Str::uuid(), 'lines' => [['purchase_line_id' => $line->id, 'quantity' => 1]]];
            $this->postJson('/api/v1/purchases/'.$p['id'].'/receive', $d)->assertForbidden();
        }
    }

    public function test_role_matrix_and_policy_refresh_match_bootstrap(): void
    {
        $resolver = app(EffectiveCapabilityResolver::class);
        foreach ([false, true] as $on) {
            $this->policy($on);
            foreach (['owner', 'admin', 'technician', 'operator'] as $role) {
                $data = $this->actingAs($this->g['a'.$role])->getJson('/api/v1/me')->assertOk()->json('data.capabilities')[$this->g['a']->id];
                $this->assertSame($resolver->resolve($this->g['a'.$role], $this->g['a']), $data);
                $this->assertSame(in_array($role, ['owner', 'admin']), $data['machine_cost.selling_price.manage']);
                $this->assertSame(in_array($role, ['owner', 'admin']) || ($role === 'operator' && $on), $data['components.lifecycle.initialize']);
                $this->assertSame($role !== 'operator' || $on, $data['incidents.create']);
                $this->assertSame($role === 'owner', $data['settings.policy.manage']);
                foreach (['inventory.purchase.create', 'inventory.receive', 'inventory.adjust', 'inventory.transfer'] as $key) {
                    $this->assertSame(in_array($role, ['owner', 'admin']) || ($role === 'operator' && $on), $data[$key], $role.' '.$key);
                }
                foreach (['counters.correct', 'incidents.manage', 'components.configure', 'inventory.opening', 'machine_cost.operating_cost.manage', 'click_targets.manage'] as $key) {
                    $this->assertSame(in_array($role, ['owner', 'admin']), $data[$key], $role.' '.$key);
                }
                foreach (['components.replace.inventory', 'components.replace.external'] as $key) {
                    $this->assertSame($role !== 'operator' || $on, $data[$key], $role.' '.$key);
                }
                $this->assertSame($role === 'owner', $data['account.manage']);
                $this->assertSame($role === 'owner', $data['audit.view']);
                $this->assertSame(in_array($role, ['owner', 'admin']), $data['operational_people.manage']);
                $this->assertSame(in_array($role, ['owner', 'admin']), $data['settings.view']);
                $this->assertFalse($data['catalog.global.manage']);

            }
        }
    }

    public function test_operator_transfer_enabled_direct_api(): void
    {
        app(InventoryLedgerService::class)->inbound($this->g['aitem'], $this->g['a1location'], 10, 2, 'opening_balance', (string) Str::uuid());
        $to = InventoryLocation::create(['account_id' => $this->g['a']->id, 'branch_id' => $this->g['a1branch']->id, 'code' => 'LT', 'name' => 'Authorized destination', 'is_active' => true]);
        $this->policy(true);
        $this->actingAs($this->g['aoperator'])->postJson('/api/v1/inventory/transfers', ['item_id' => $this->g['aitem']->id, 'from_location_id' => $this->g['a1location']->id, 'to_location_id' => $to->id, 'quantity' => 1, 'client_request_id' => (string) Str::uuid()])->assertCreated();
    }

    public function test_policy_mutation_updates_bootstrap_and_settings_matrix_without_new_login(): void
    {
        $this->policy(false);
        $this->actingAs($this->g['aowner'])->patchJson('/api/v1/accounts/'.$this->g['a']->id.'/settings/policy', ['operator_can_log_errors' => true])->assertOk();
        $settings = $this->getJson('/api/v1/accounts/'.$this->g['a']->id.'/settings')->assertOk()->json('data');
        $this->assertTrue($settings['capability_matrix']['operator']['incidents.create']);
        $this->assertFalse($settings['capability_matrix']['technician']['components.lifecycle.initialize']);
        $this->actingAs($this->g['aoperator']);
        $this->assertTrue($this->getJson('/api/v1/me')->assertOk()->json('data.capabilities')[$this->g['a']->id]['incidents.create']);
        $this->hitAction('incident')->assertCreated();
    }
}
