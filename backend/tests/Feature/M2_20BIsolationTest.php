<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ComponentCatalog;
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
use App\Models\User;
use App\Services\CreateCounterReading;
use App\Services\InventoryLedgerService;
use App\Services\ReplaceMachineComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class M2_20BIsolationTest extends TestCase
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
            $a = $this->g[$key] = Account::create(['code' => strtoupper($key), 'name' => 'Synthetic '.$key, 'status' => 'active']);
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

    private function incident(string $key, ?string $requestId = null): OperationalIncident
    {
        return OperationalIncident::create(['account_id' => $this->g[$key[0]]->id, 'branch_id' => $this->g[$key.'branch']->id, 'machine_id' => $this->g[$key.'machine']->id, 'occurred_at' => '2026-08-10 12:00:00', 'category' => 'error', 'incident_type' => 'test', 'description' => 'Private '.$key, 'status' => 'open', 'material_loss' => 10, 'service_loss' => 0, 'penalty_multiplier' => 1, 'client_request_id' => $requestId ?? (string) Str::uuid()]);
    }

    private function incidentPayload(array $extra = []): array
    {
        return $extra + ['occurred_at' => '2026-08-10 12:00:00', 'category' => 'error', 'incident_type' => 'test', 'description' => 'Test', 'client_request_id' => (string) Str::uuid(), 'change_reason' => 'Test correction'];
    }

    private function purchasePayload(string $key = 'a1', array $extra = []): array
    {
        return $extra + ['account_id' => $this->g[$key[0]]->id, 'branch_id' => $this->g[$key.'branch']->id, 'purchase_number' => (string) Str::uuid(), 'purchase_date' => '2026-08-10', 'client_request_id' => (string) Str::uuid(), 'lines' => [['inventory_item_id' => $this->g[$key[0].'item']->id, 'quantity' => 3, 'unit_cost' => 10]]];
    }

    public static function otherResources(): array
    {
        return ['same account' => ['a2'], 'foreign account' => ['b1']];
    }

    #[DataProvider('otherResources')]
    public function test_lifecycle_replay_is_bound_to_component(string $other): void
    {
        $payload = ['started_at' => '2026-08-10', 'source' => 'manual', 'client_request_id' => (string) Str::uuid()];
        $this->actingAs($this->g[$other[0].'owner'])->postJson('/api/v1/machine-components/'.$this->g[$other.'mc']->id.'/lifecycles', $payload)->assertCreated();
        $this->actingAs($this->g['aadmin'])->postJson('/api/v1/machine-components/'.$this->g['a1mc']->id.'/lifecycles', $payload)->assertConflict();
        $this->assertDatabaseCount('component_lifecycles', 1);
    }

    public function test_lifecycle_exact_retry_and_changed_payload(): void
    {
        $url = '/api/v1/machine-components/'.$this->g['a1mc']->id.'/lifecycles';
        $d = ['started_at' => '2026-08-10', 'source' => 'manual', 'client_request_id' => (string) Str::uuid()];
        $id = $this->actingAs($this->g['aadmin'])->postJson($url, $d)->assertCreated()->json('data.id');
        $this->postJson($url, $d)->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson($url, array_replace($d, ['started_at' => '2026-08-11']))->assertConflict();
    }

    public function test_incident_replay_cannot_return_another_branch(): void
    {
        $old = $this->incident('a2');
        $this->actingAs($this->g['aadmin'])->postJson('/api/v1/accounts/'.$this->g['a']->id.'/branches/'.$this->g['a1branch']->id.'/incidents', $this->incidentPayload(['client_request_id' => $old->client_request_id]))->assertConflict();
        $this->assertDatabaseCount('operational_incidents', 1);
    }

    public function test_report_omission_is_not_account_wide_for_restricted_user(): void
    {
        $this->incident('a1')->update(['machine_id' => null]);
        $this->incident('a2')->update(['machine_id' => null]);
        $url = '/api/v1/reports?account_id='.$this->g['a']->id.'&period_start=2026-08-01&period_end=2026-08-31';
        $r = $this->actingAs($this->g['aadmin'])->getJson($url)->assertOk()->assertJsonPath('overview.active_machines', 1)->assertJsonCount(1, 'incidents')->assertJsonCount(1, 'machine_cost');
        $this->assertStringNotContainsString($this->g['a2machine']->id, $r->getContent());
        $this->getJson($url.'&branch_id='.$this->g['a1branch']->id)->assertOk();
        $this->getJson($url.'&branch_id='.$this->g['a2branch']->id)->assertForbidden();
        $this->getJson($url.'&machine_id='.$this->g['a2machine']->id)->assertForbidden();
        $this->getJson($url.'&branch_id='.$this->g['a1branch']->id.'&machine_id='.$this->g['a2machine']->id)->assertForbidden();
        $this->actingAs($this->g['aowner'])->getJson($url)->assertOk()->assertJsonPath('overview.active_machines', 2)->assertJsonCount(2, 'incidents');
    }

    public static function references(): array
    {
        return array_map(fn ($x) => [$x], ['machine', 'model', 'slot', 'item', 'supplier', 'person', 'catalog']);
    }

    #[DataProvider('references')]
    public function test_foreign_private_reference_is_rejected_without_mutation(string $kind): void
    {
        $this->actingAs($this->g['aowner']);
        [$method,$url,$d,$table] = match ($kind) {
            'machine' => ['PATCH', '/api/v1/machines/'.$this->g['a1machine']->id, ['machine_model_id' => $this->g['bmodel']->id, 'machine_code' => 'A1', 'display_name' => 'Changed'], 'machines'],
            'model' => ['PUT', '/api/v1/machine-models/'.$this->g['amodel']->id, ['manufacturer_id' => $this->g['bmanufacturer']->id, 'model_code' => 'A', 'name' => 'Changed'], 'machine_models'],
            'slot' => ['POST', '/api/v1/model-profiles/'.$this->g['aprofile']->id.'/slots', ['component_id' => $this->g['bcomponent']->id, 'slot_code' => 'BAD'], 'model_profile_slots'],
            'item' => ['PUT', '/api/v1/inventory/items/'.$this->g['aitem']->id, ['account_id' => $this->g['a']->id, 'component_id' => $this->g['bcomponent']->id, 'name' => 'Changed', 'unit' => 'pcs'], 'inventory_items'],
            'supplier' => ['POST', '/api/v1/purchases', $this->purchasePayload('a1', ['supplier_id' => $this->g['bsupplier']->id]), 'purchases'],
            'person' => ['POST', '/api/v1/machines/'.$this->g['a1machine']->id.'/cost/operating-costs', ['category' => 'service', 'amount' => 10, 'allocation_method' => 'direct', 'description' => 'Test', 'effective_at' => '2026-08-10', 'period_start' => null, 'period_end' => null, 'operational_person_id' => $this->g['bperson']->id, 'client_request_id' => (string) Str::uuid()], 'machine_operating_costs'],
            'catalog' => ['PUT', '/api/v1/components/'.$this->g['acomponent']->id, ['manufacturer_id' => $this->g['bmanufacturer']->id, 'code' => 'A', 'name' => 'Changed'], 'component_catalogs'],
        };
        $before = DB::table($table)->orderBy('id')->get()->toJson();
        $r = $this->json($method, $url, $d)->assertUnprocessable();
        $this->assertSame($before, DB::table($table)->orderBy('id')->get()->toJson());
        $this->assertStringNotContainsString('Private', $r->getContent());
    }

    public function test_purchase_branch_and_receipt_purchase_branch_are_authorized(): void
    {
        $d = $this->purchasePayload('a2');
        $this->actingAs($this->g['aadmin'])->postJson('/api/v1/purchases', $d)->assertForbidden();
        $this->assertDatabaseCount('purchases', 0);
        $id = $this->actingAs($this->g['aowner'])->postJson('/api/v1/purchases', $d)->assertCreated()->json('data.id');
        $line = DB::table('purchase_lines')->where('purchase_id', $id)->first();
        $this->actingAs($this->g['aadmin'])->postJson('/api/v1/purchases/'.$id.'/receive', ['location_id' => $this->g['a1location']->id, 'lines' => [['purchase_line_id' => $line->id, 'quantity' => 1]], 'client_request_id' => (string) Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('receipts', 0);
    }

    public static function incidentActions(): array
    {
        return ['edit' => ['PATCH', ''], 'solve' => ['POST', '/solve'], 'void' => ['POST', '/void']];
    }

    #[DataProvider('incidentActions')]
    public function test_incident_mutation_requires_own_branch(string $method, string $suffix): void
    {
        $i = $this->incident('a2');
        $before = $i->fresh()->getRawOriginal();
        $this->actingAs($this->g['aadmin'])->json($method, '/api/v1/incidents/'.$i->id.$suffix, $this->incidentPayload(['void_reason' => 'Test']))->assertForbidden();
        $this->assertSame($before, $i->fresh()->getRawOriginal());
    }

    public function test_incident_cannot_move_to_unassigned_machine(): void
    {
        $i = $this->incident('a1');
        $before = $i->fresh()->getRawOriginal();
        $this->actingAs($this->g['aadmin'])->patchJson('/api/v1/incidents/'.$i->id, $this->incidentPayload(['machine_id' => $this->g['a2machine']->id]))->assertUnprocessable();
        $this->assertSame($before, $i->fresh()->getRawOriginal());
    }

    public function test_counter_correction_requires_machine_branch(): void
    {
        $c = CounterReading::create(['account_id' => $this->g['a']->id, 'machine_id' => $this->g['a2machine']->id, 'counter_type_id' => '00000000-0000-0000-0000-000000000001', 'client_request_id' => (string) Str::uuid(), 'reading_value' => 100, 'observed_at' => '2026-08-10', 'status' => 'effective']);
        $this->actingAs($this->g['aadmin'])->postJson('/api/v1/counter-readings/'.$c->id.'/correction', ['correction_reason' => 'Test'])->assertForbidden();
        $this->assertSame('effective', $c->fresh()->status);
    }

    public static function retries(): array
    {
        return array_map(fn ($v) => [$v], ['adjustment', 'replacement', 'calendar', 'receipt', 'purchase']);
    }

    #[DataProvider('retries')]
    public function test_retries_reject_wrong_resource(string $kind): void
    {
        $this->actingAs($this->g['aowner']);
        $key = (string) Str::uuid();
        if ($kind === 'adjustment') {
            $d = ['item_id' => $this->g['aitem']->id, 'location_id' => $this->g['a2location']->id, 'quantity' => 2, 'unit_cost' => 1, 'reason' => 'Test', 'client_request_id' => $key];
            $this->postJson('/api/v1/inventory/adjustments', $d)->assertCreated();
            $this->actingAs($this->g['aadmin'])->postJson('/api/v1/inventory/adjustments', array_replace($d, ['location_id' => $this->g['a1location']->id]))->assertConflict();
            $this->assertSame(1, DB::table('inventory_movements')->where('account_id', $this->g['a']->id)->count());
        } elseif ($kind === 'replacement') {
            $d = ['inventory_source' => 'external_untracked', 'external_reason' => 'Test', 'replaced_at' => '2026-08-10', 'client_request_id' => $key];
            $this->postJson('/api/v1/machine-components/'.$this->g['a2mc']->id.'/replacements', $d)->assertCreated();
            $this->actingAs($this->g['aadmin'])->postJson('/api/v1/machine-components/'.$this->g['a1mc']->id.'/replacements', $d)->assertConflict();
            $this->assertDatabaseCount('component_replacements', 1);
        } elseif ($kind === 'calendar') {
            $d = ['calendar_date' => '2026-08-10', 'exception_type' => 'other', 'client_request_id' => $key];
            $this->postJson('/api/v1/machines/'.$this->g['a2machine']->id.'/click-target/calendar-exceptions', $d)->assertCreated();
            $this->actingAs($this->g['aadmin'])->postJson('/api/v1/machines/'.$this->g['a1machine']->id.'/click-target/calendar-exceptions', $d)->assertConflict();
        } elseif ($kind === 'purchase') {
            $d = $this->purchasePayload('a2');
            $this->postJson('/api/v1/purchases', $d)->assertCreated();
            $this->actingAs($this->g['aadmin'])->postJson('/api/v1/purchases', array_replace($d, ['branch_id' => $this->g['a1branch']->id]))->assertConflict();
            $this->assertDatabaseCount('purchases', 1);
        } else {
            $p1 = $this->postJson('/api/v1/purchases', $this->purchasePayload())->assertCreated()->json('data.id');
            $p2 = $this->postJson('/api/v1/purchases', $this->purchasePayload())->assertCreated()->json('data.id');
            $line = DB::table('purchase_lines')->where('purchase_id', $p1)->first();
            $d = ['location_id' => $this->g['a1location']->id, 'lines' => [['purchase_line_id' => $line->id, 'quantity' => 1]], 'client_request_id' => $key];
            $this->postJson('/api/v1/purchases/'.$p1.'/receive', $d)->assertCreated();
            $this->postJson('/api/v1/purchases/'.$p2.'/receive', $d)->assertConflict();
            $this->assertDatabaseCount('receipts', 1);
        }
    }

    public static function exactDomains(): array
    {
        return array_map(fn ($v) => [$v], ['incident', 'purchase', 'receipt', 'adjustment', 'replacement', 'calendar', 'selling_price']);
    }

    #[DataProvider('exactDomains')]
    public function test_exact_retry_and_changed_payload_by_domain(string $kind): void
    {
        $this->actingAs($this->g['aadmin']);
        $key = (string) Str::uuid();
        [$url, $d, $change] = match ($kind) {
            'incident' => ['/api/v1/accounts/'.$this->g['a']->id.'/branches/'.$this->g['a1branch']->id.'/incidents', $this->incidentPayload(['material_loss' => 20]), ['material_loss' => 21]],
            'purchase' => ['/api/v1/purchases', $this->purchasePayload(), ['notes' => 'Different request']],
            'adjustment' => ['/api/v1/inventory/adjustments', ['item_id' => $this->g['aitem']->id, 'location_id' => $this->g['a1location']->id, 'quantity' => 2, 'unit_cost' => 3, 'reason' => 'Test', 'client_request_id' => $key], ['unit_cost' => 4]],
            'replacement' => ['/api/v1/machine-components/'.$this->g['a1mc']->id.'/replacements', ['inventory_source' => 'external_untracked', 'external_reason' => 'Test', 'replaced_at' => '2026-08-10', 'client_request_id' => $key], ['notes' => 'Different request']],
            'calendar' => ['/api/v1/machines/'.$this->g['a1machine']->id.'/click-target/calendar-exceptions', ['calendar_date' => '2026-08-10', 'exception_type' => 'other', 'client_request_id' => $key], ['calendar_date' => '2026-08-11']],
            'selling_price' => ['/api/v1/machines/'.$this->g['a1machine']->id.'/cost/selling-prices', ['price_per_click' => 10, 'effective_from' => '2026-08-10', 'client_request_id' => $key], ['notes' => 'Changed']],
            'receipt' => ['', [], []],
        };
        if ($kind === 'receipt') {
            $id = $this->postJson('/api/v1/purchases', $this->purchasePayload())->assertCreated()->json('data.id');
            $line = DB::table('purchase_lines')->where('purchase_id', $id)->first();
            $url = '/api/v1/purchases/'.$id.'/receive';
            $d = ['location_id' => $this->g['a1location']->id, 'lines' => [['purchase_line_id' => $line->id, 'quantity' => 1]], 'client_request_id' => $key];
            $change = ['lines' => [['purchase_line_id' => $line->id, 'quantity' => 2]]];
        }
        $idPath = $kind === 'incident' ? 'incident.id' : 'data.id';
        $first = $this->postJson($url, $d)->assertSuccessful()->json($idPath);
        $this->assertNotEmpty($first);
        $before = $this->operationalState();
        $this->postJson($url, $d)->assertSuccessful()->assertJsonPath($idPath, $first);
        $this->postJson($url, array_replace($d, $change))->assertConflict();
        if ($kind === 'incident') {
            unset($d['material_loss']);
            $this->postJson($url, $d)->assertConflict();
        }
        $this->assertSame($before, $this->operationalState());
    }

    private function operationalState(): array
    {
        $state = [];
        foreach (['purchases', 'purchase_lines', 'receipts', 'receipt_lines', 'inventory_movements', 'fifo_layers', 'fifo_allocations', 'component_replacements', 'component_lifecycles', 'operational_incidents', 'operational_incident_revisions', 'counter_readings'] as $table) {
            if (Schema::hasTable($table)) {
                $state[$table] = DB::table($table)->orderBy('id')->get()->toJson();
            }
        }

        return $state;
    }

    public static function permittedScopes(): array
    {
        return ['global' => ['global'], 'owned' => ['a']];
    }

    #[DataProvider('permittedScopes')]
    public function test_global_and_own_catalog_references_remain_supported(string $scope): void
    {
        $this->actingAs($this->g['aowner']);
        $maker = $this->g[$scope === 'global' ? 'globalManufacturer' : 'amanufacturer'];
        $component = $this->g[$scope === 'global' ? 'globalComponent' : 'acomponent'];
        $model = $scope === 'global' ? MachineModel::create(['manufacturer_id' => $maker->id, 'model_code' => 'G', 'name' => 'Global model', 'is_active' => true]) : $this->g['amodel'];
        $this->putJson('/api/v1/machine-models/'.$this->g['amodel']->id, ['manufacturer_id' => $maker->id, 'model_code' => 'A', 'name' => 'Own model'])->assertOk()->assertJsonPath('data.manufacturer.id', (string) $maker->id);
        $this->patchJson('/api/v1/machines/'.$this->g['a1machine']->id, ['machine_model_id' => $model->id, 'machine_code' => 'A1', 'display_name' => 'Own machine'])->assertOk()->assertJsonPath('data.model.id', (string) $model->id);
        $this->postJson('/api/v1/model-profiles/'.$this->g['aprofile']->id.'/slots', ['component_id' => $component->id, 'slot_code' => 'SAFE'])->assertCreated()->assertJsonPath('data.component.id', (string) $component->id);
        $this->putJson('/api/v1/inventory/items/'.$this->g['aitem']->id, ['account_id' => $this->g['a']->id, 'component_id' => $component->id, 'name' => 'Own item', 'unit' => 'pcs'])->assertOk()->assertJsonPath('data.component.id', (string) $component->id);
        $this->postJson('/api/v1/purchases', $this->purchasePayload('a1', ['supplier_id' => $this->g['asupplier']->id]))->assertCreated();
    }

    public function test_existing_foreign_catalog_references_are_not_serialized(): void
    {
        $this->g['a1machine']->update(['machine_model_id' => $this->g['bmodel']->id]);
        $this->g['amodel']->update(['manufacturer_id' => $this->g['bmanufacturer']->id]);
        $this->g['aitem']->update(['component_id' => $this->g['bcomponent']->id]);
        $slot = ModelProfileSlot::create(['profile_id' => $this->g['aprofile']->id, 'component_id' => $this->g['bcomponent']->id, 'slot_code' => 'BAD']);
        $this->actingAs($this->g['aadmin'])->getJson('/api/v1/machines/'.$this->g['a1machine']->id)->assertOk()->assertJsonPath('data.model', null);
        // Mixed-account eager loading must not match a valid B reference onto A.
        $batch = Machine::with('model.manufacturer')->whereIn('id', [$this->g['a1machine']->id, $this->g['b1machine']->id])->get()->keyBy('id');
        $this->assertNull($batch[(string) $this->g['a1machine']->id]->model);
        $this->assertNotNull($batch[(string) $this->g['b1machine']->id]->model);
        $this->assertNull($this->g['amodel']->fresh()->load('manufacturer')->manufacturer);
        $this->assertNull($this->g['aitem']->fresh()->load('component')->component);
        $this->assertNull($slot->fresh()->load('component')->component);
        $this->assertNull($this->g['aitem']->fresh()->component);
    }

    public function test_all_report_areas_are_scoped_including_current_inventory_consumption(): void
    {
        $this->actingAs($this->g['aowner']);
        foreach (['a1', 'a2'] as $key) {
            $this->incident($key);
            CounterReading::create(['account_id' => $this->g['a']->id, 'machine_id' => $this->g[$key.'machine']->id, 'counter_type_id' => '00000000-0000-0000-0000-000000000001', 'client_request_id' => (string) Str::uuid(), 'reading_value' => 100, 'observed_at' => '2026-08-10', 'status' => 'effective', 'operator_name_snapshot' => 'Operator '.$key]);
            $this->postJson('/api/v1/inventory/adjustments', ['item_id' => $this->g['aitem']->id, 'location_id' => $this->g[$key.'location']->id, 'quantity' => 2, 'unit_cost' => 5, 'reason' => 'Test', 'client_request_id' => (string) Str::uuid()])->assertCreated();
            $this->postJson('/api/v1/machine-components/'.$this->g[$key.'mc']->id.'/replacements', ['inventory_source' => 'inventory', 'inventory_item_id' => $this->g['aitem']->id, 'inventory_location_id' => $this->g[$key.'location']->id, 'quantity' => 1, 'replaced_at' => '2026-08-10', 'client_request_id' => (string) Str::uuid()])->assertCreated();
        }
        // Movement posting uses now(); move synthetic evidence into this report period.
        DB::table('inventory_movements')->update(['occurred_at' => '2026-08-10 12:00:00']);
        $url = '/api/v1/reports?account_id='.$this->g['a']->id.'&period_start=2026-08-01&period_end=2026-08-31';
        $response = $this->actingAs($this->g['aadmin'])->getJson($url)->assertOk();
        foreach (['machine_cost', 'performance', 'counter', 'operator_activity', 'incidents', 'replacements', 'components', 'inventory_consumption'] as $area) {
            $response->assertJsonCount(1, $area);
        }
        $this->assertStringNotContainsString('Operator a2', $response->getContent());
        $this->assertStringNotContainsString((string) $this->g['a2machine']->id, $response->getContent());
        $this->actingAs($this->g['aowner'])->getJson($url)->assertOk()->assertJsonCount(2, 'inventory_consumption');
        $this->actingAs($this->g['aadmin'])->getJson($url.'&machine_id='.$this->g['a1machine']->id)->assertOk()->assertJsonCount(1, 'inventory_consumption');
        $this->g['aadmin']->memberships()->first()->branchAssignments()->update(['is_active' => false]);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'machine_cost')->assertJsonCount(0, 'incidents')->assertJsonCount(0, 'inventory_consumption');
    }

    public function test_receiving_requires_location_branch_and_failed_lines_roll_back_everything(): void
    {
        $this->actingAs($this->g['aadmin']);
        $id = $this->postJson('/api/v1/purchases', $this->purchasePayload())->assertCreated()->json('data.id');
        $line = DB::table('purchase_lines')->where('purchase_id', $id)->first();
        $d = ['location_id' => $this->g['a2location']->id, 'lines' => [['purchase_line_id' => $line->id, 'quantity' => 1]], 'client_request_id' => (string) Str::uuid()];
        $before = $this->operationalState();
        $this->postJson('/api/v1/purchases/'.$id.'/receive', $d)->assertForbidden();
        $d['location_id'] = $this->g['a1location']->id;
        $d['lines'][] = ['purchase_line_id' => (string) Str::uuid(), 'quantity' => 1];
        $this->postJson('/api/v1/purchases/'.$id.'/receive', $d)->assertConflict();
        $this->assertSame($before, $this->operationalState());
    }

    public function test_receipt_pic_retry_and_own_operating_person(): void
    {
        $person = $this->g['aperson'];
        OperationalPersonBranch::create(['account_id' => $this->g['a']->id, 'person_id' => $person->id, 'branch_id' => $this->g['a1branch']->id, 'is_active' => true, 'can_record_counter' => true]);
        $this->actingAs($this->g['aadmin']);
        $id = $this->postJson('/api/v1/purchases', $this->purchasePayload())->assertCreated()->json('data.id');
        $line = DB::table('purchase_lines')->where('purchase_id', $id)->first();
        $d = ['location_id' => $this->g['a1location']->id, 'person_id' => $person->id, 'lines' => [['purchase_line_id' => $line->id, 'quantity' => 1]], 'client_request_id' => (string) Str::uuid()];
        $url = '/api/v1/purchases/'.$id.'/receive';
        $receipt = $this->postJson($url, $d)->assertCreated()->json('data.id');
        $before = $this->operationalState();
        $this->postJson($url, $d)->assertCreated()->assertJsonPath('data.id', $receipt);
        unset($d['person_id']);
        $this->postJson($url, $d)->assertConflict();
        $this->assertSame($before, $this->operationalState());
        $this->postJson('/api/v1/machines/'.$this->g['a1machine']->id.'/cost/operating-costs', ['category' => 'service', 'amount' => 10, 'allocation_method' => 'direct', 'description' => 'Own person', 'operational_person_id' => $person->id, 'client_request_id' => (string) Str::uuid()])->assertCreated();
    }

    public function test_adjustment_sign_change_and_transfer_destination_replay_are_rejected(): void
    {
        $this->actingAs($this->g['aowner']);
        $d = ['item_id' => $this->g['aitem']->id, 'location_id' => $this->g['a1location']->id, 'quantity' => 5, 'unit_cost' => 1, 'reason' => 'Test', 'client_request_id' => (string) Str::uuid()];
        $this->postJson('/api/v1/inventory/adjustments', $d)->assertCreated();
        $before = $this->operationalState();
        $this->postJson('/api/v1/inventory/adjustments', array_replace($d, ['quantity' => -5]))->assertConflict();
        $this->assertSame($before, $this->operationalState());
        $ledger = app(InventoryLedgerService::class);
        $key = (string) Str::uuid();
        $one = $ledger->transfer($this->g['aitem'], $this->g['a1location'], $this->g['a2location'], 1, $key);
        $two = $ledger->transfer($this->g['aitem'], $this->g['a1location'], $this->g['a2location'], 1, $key);
        $this->assertSame((string) $one[0]->id, (string) $two[0]->id);
        $third = InventoryLocation::create(['account_id' => $this->g['a']->id, 'branch_id' => $this->g['a1branch']->id, 'code' => 'L3', 'name' => 'Third', 'is_active' => true]);
        $before = $this->operationalState();
        try {
            $ledger->transfer($this->g['aitem'], $this->g['a1location'], $third, 1, $key);
            $this->fail('Different transfer destination must conflict');
        } catch (ConflictHttpException $e) {
            $this->assertSame($before, $this->operationalState());
        }
    }

    public function test_global_location_consumption_does_not_expose_unassigned_machine(): void
    {
        $location = InventoryLocation::create(['account_id' => $this->g['a']->id, 'branch_id' => null, 'code' => 'GLOBAL', 'name' => 'Account store', 'is_active' => true]);
        $ledger = app(InventoryLedgerService::class);
        $ledger->inbound($this->g['aitem'], $location, 5, 1, 'opening_balance', (string) Str::uuid());
        foreach (['a1', 'a2'] as $key) {
            app(ReplaceMachineComponent::class)->execute($this->g[$key.'mc'], ['inventory_source' => 'inventory', 'inventory_item_id' => $this->g['aitem']->id, 'inventory_location_id' => $location->id, 'quantity' => 1, 'replaced_at' => '2026-08-10', 'client_request_id' => (string) Str::uuid()]);
        }
        DB::table('inventory_movements')->where('account_id', $this->g['a']->id)->update(['occurred_at' => '2026-08-10 12:00:00']);
        $url = '/api/v1/reports?account_id='.$this->g['a']->id.'&period_start=2026-08-01&period_end=2026-08-31';
        $this->actingAs($this->g['aadmin'])->getJson($url)->assertOk()->assertJsonCount(1, 'inventory_consumption')->assertJsonPath('inventory_consumption.0.machine_id', (string) $this->g['a1machine']->id);
        $this->actingAs($this->g['aowner'])->getJson($url)->assertOk()->assertJsonCount(2, 'inventory_consumption');
    }

    public function test_counter_create_and_correction_retry_payload_binding(): void
    {
        $person = $this->g['aperson'];
        OperationalPersonBranch::create(['account_id' => $this->g['a']->id, 'person_id' => $person->id, 'branch_id' => $this->g['a1branch']->id, 'is_active' => true, 'can_record_counter' => true]);
        $service = app(CreateCounterReading::class);
        $data = ['operator_person_id' => $person->id, 'reading_value' => 100, 'observed_at' => '2026-08-10T10:00:00+07:00', 'client_request_id' => (string) Str::uuid()];
        $first = $service->execute($this->g['aadmin'], $this->g['a1machine'], $data);
        $again = $service->execute($this->g['aadmin'], $this->g['a1machine'], $data);
        $this->assertSame((string) $first->id, (string) $again->id);
        try {
            $service->execute($this->g['aadmin'], $this->g['a1machine'], array_replace($data, ['observed_at' => '2026-08-11T10:00:00+07:00']));
            $this->fail('Changed counter time must conflict');
        } catch (ConflictHttpException $e) {
            $this->assertSame(1, CounterReading::where('account_id', $this->g['a']->id)->count());
        }
        $url = '/api/v1/counter-readings/'.$first->id.'/correction';
        $d = ['replacement_value' => 110, 'correction_reason' => 'Fix', 'client_request_id' => (string) Str::uuid()];
        $id = $this->actingAs($this->g['aadmin'])->postJson($url, $d)->assertSuccessful()->json('data.id');
        $this->postJson($url, $d)->assertSuccessful()->assertJsonPath('data.id', $id);
        $before = $this->operationalState();
        $this->postJson($url, array_replace($d, ['correction_reason' => 'Another reason']))->assertConflict();
        $this->assertSame($before, $this->operationalState());
    }

    public function test_concurrent_cross_resource_inventory_key_has_one_winner_on_mysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires MySQL row locks and committed worker visibility.');
        }
        $ledger = app(InventoryLedgerService::class);
        foreach (['a1', 'a2'] as $key) {
            $ledger->inbound($this->g['aitem'], $this->g[$key.'location'], 5, 1, 'opening_balance', (string) Str::uuid());
        }
        DB::connection()->commit();
        try {
            $request = (string) Str::uuid();
            $processes = [];
            foreach (['a1', 'a2'] as $key) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/consume_inventory_same_request.php'), (string) $this->g['aitem']->id, (string) $this->g[$key.'location']->id, $request], base_path());
                $process->start();
                $processes[] = $process;
            }
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $results[] = json_decode($process->getOutput(), true);
            }
            $this->assertSame(1, count(array_filter($results, fn ($r) => ($r['ok'] ?? false) === true)), json_encode($results));
            $this->assertSame(1, DB::table('inventory_movements')->where('account_id', $this->g['a']->id)->where('client_request_id', $request)->count());
        } finally {
            DB::connection()->beginTransaction();
        }
    }
}
