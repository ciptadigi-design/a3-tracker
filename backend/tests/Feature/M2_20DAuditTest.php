<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ComponentCatalog;
use App\Models\Machine;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\PlatformUserPrivilege;
use App\Models\User;
use App\Services\GovernanceAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class M2_20DAuditTest extends TestCase
{
    use RefreshDatabase;

    private array $g = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['a', 'b'] as $key) {
            $a = $this->g[$key] = Account::create(['code' => 'M220D_'.$key, 'name' => 'Tenant '.$key, 'status' => 'active']);
            $b = $this->g[$key.'branch'] = $a->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_active' => true]);
            foreach (['owner', 'admin', 'technician', 'operator'] as $role) {
                $u = $this->g[$key.$role] = User::factory()->create(['status' => 'active']);
                $m = $this->g[$key.$role.'m'] = $a->memberships()->create(['user_id' => $u->id, 'role' => $role, 'status' => 'active']);
                $m->branchAssignments()->create(['account_id' => $a->id, 'branch_id' => $b->id, 'is_active' => true]);
            }
        }
        $this->g['platform'] = User::factory()->create(['status' => 'active']);
        PlatformUserPrivilege::create(['user_id' => $this->g['platform']->id, 'role' => 'superuser', 'is_active' => true]);
        $maker = Manufacturer::create(['code' => 'M220D_GLOBAL', 'name' => 'Global', 'is_active' => true]);
        $this->g['model'] = MachineModel::create(['manufacturer_id' => $maker->id, 'model_code' => 'M220D_GLOBAL', 'name' => 'Global', 'is_active' => true]);
        $this->g['machine'] = Machine::create(['account_id' => $this->g['a']->id, 'branch_id' => $this->g['abranch']->id, 'machine_model_id' => $this->g['model']->id, 'machine_code' => 'M', 'display_name' => 'Machine', 'status' => 'active']);
        $this->actingAs($this->g['aowner']);
    }

    private function base(): string
    {
        return '/api/v1/accounts/'.$this->g['a']->id;
    }

    private function evidence(string $action): array
    {
        $row = DB::table('governance_audit_logs')->where('action', $action)->orderByDesc('created_at')->first();
        $this->assertNotNull($row, $action);
        $this->assertSame($this->g['a']->id, $row->account_id);
        $data = json_decode($row->metadata, true, 512, JSON_THROW_ON_ERROR);
        foreach ($data['changes'] as &$change) {
            $change = ['before' => $change['before'], 'after' => $change['after']];
        }

        return $data;
    }

    public function test_membership_events_and_failed_updates(): void
    {
        $payload = ['name' => 'New member', 'email' => 'new@example.test', 'username' => 'new.member', 'password' => 'synthetic-password', 'role' => 'operator', 'branch_ids' => [$this->g['abranch']->id]];
        $m = $this->postJson($this->base().'/members', $payload)->assertCreated()->json('data');
        $this->assertSame('operator', $this->evidence('membership.created')['changes']['role']['after']);
        $url = $this->base().'/members/'.$m['id'];
        $this->patchJson($url, ['role' => 'admin', 'status' => 'suspended', 'branch_ids' => []])->assertOk();
        $this->assertSame(['before' => 'operator', 'after' => 'admin'], $this->evidence('membership.role_changed')['changes']['role']);
        $this->assertSame(['before' => 'active', 'after' => 'suspended'], $this->evidence('membership.status_changed')['changes']['status']);
        $this->assertSame(['before' => [$this->g['abranch']->id], 'after' => []], $this->evidence('membership.branch_assignments_changed')['changes']['branch_ids']);
        $count = DB::table('governance_audit_logs')->count();
        $this->patchJson($url, ['role' => 'operator', 'branch_ids' => [$this->g['bbranch']->id]])->assertUnprocessable();
        $this->assertDatabaseHas('account_memberships', ['id' => $m['id'], 'role' => 'admin', 'status' => 'suspended']);
        $this->assertDatabaseCount('governance_audit_logs', $count);
        $raw = DB::table('governance_audit_logs')->get()->toJson();
        foreach (['password', 'synthetic-password', '$2y$', 'session_version', 'token'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
    }

    public function test_policy_only_changed_key_and_noop(): void
    {
        $this->patchJson($this->base().'/settings/policy', ['operator_can_adjust_inventory' => true, 'operator_can_transfer_inventory' => false])->assertOk();
        $this->assertSame(['operator_can_adjust_inventory' => ['before' => false, 'after' => true]], $this->evidence('account.policy_updated')['changes']);
        $this->patchJson($this->base().'/settings/policy', ['operator_can_adjust_inventory' => true])->assertOk();
        $this->assertDatabaseCount('governance_audit_logs', 1);
    }

    public function test_branch_machine_and_platform_origin(): void
    {
        $this->actingAs($this->g['platform']);
        $branch = $this->postJson($this->base().'/branches', ['code' => 'SECOND', 'name' => 'Second'])->assertCreated()->json('data');
        $this->assertSame('platform', $this->evidence('branch.created')['actor_type']);
        $url = $this->base().'/branches/'.$branch['id'];
        $this->putJson($url, ['name' => 'Renamed'])->assertOk();
        $this->assertSame('Second', $this->evidence('branch.updated')['changes']['name']['before']);
        $this->putJson($url, ['is_active' => false])->assertOk();
        $this->evidence('branch.archived');
        $this->putJson($url, ['is_active' => true])->assertOk();
        $this->evidence('branch.restored');
        $data = ['machine_model_id' => $this->g['model']->id, 'machine_code' => 'NEW', 'display_name' => 'New'];
        $machine = $this->postJson('/api/v1/branches/'.$branch['id'].'/machines', $data)->assertCreated()->json('data');
        $this->evidence('machine.created');
        $this->patchJson('/api/v1/machines/'.$machine['id'], $data + ['timezone' => 'Asia/Jakarta'])->assertOk();
        $this->assertArrayHasKey('timezone', $this->evidence('machine.updated')['changes']);
        $this->patchJson('/api/v1/machines/'.$machine['id'].'/status', ['status' => 'retired'])->assertOk();
        $this->assertSame(['before' => 'active', 'after' => 'retired'], $this->evidence('machine.status_changed')['changes']['status']);
        $this->putJson($this->base(), ['name' => 'Changed tenant'])->assertOk();
        $this->evidence('account.updated');
    }

    public function test_supplier_and_person_history(): void
    {
        $data = ['account_id' => $this->g['a']->id, 'code' => 'S', 'name' => 'Supplier'];
        $s = $this->postJson('/api/v1/inventory/suppliers', $data)->assertOk()->json('data');
        $this->evidence('supplier.created');
        $this->putJson('/api/v1/inventory/suppliers/'.$s['id'], array_replace($data, ['name' => 'Changed', 'is_active' => false]))->assertOk();
        $this->assertArrayHasKey('is_active', $this->evidence('supplier.archived')['changes']);
        $this->postJson('/api/v1/inventory/suppliers/'.$s['id'].'/branches', ['branch_id' => $this->g['abranch']->id])->assertCreated();
        $this->evidence('supplier.branch_assignments_changed');
        $this->deleteJson('/api/v1/inventory/suppliers/'.$s['id'].'/branches/'.$this->g['abranch']->id)->assertNoContent();
        $this->deleteJson('/api/v1/inventory/suppliers/'.$s['id'])->assertNoContent();
        $this->evidence('supplier.deleted');
        $this->actingAs($this->g['platform']);
        $p = $this->postJson($this->base().'/operational-people', ['name' => 'Person'])->assertCreated()->json('data');
        $this->evidence('operational_person.created');
        $this->putJson($this->base().'/operational-people/'.$p['id'], ['name' => 'Renamed'])->assertOk();
        $this->evidence('operational_person.updated');
        $this->putJson('/api/v1/operational-people/'.$p['id'].'/branches', ['assignments' => [['branch_id' => $this->g['abranch']->id, 'can_record_counter' => true]]])->assertOk();
        $this->evidence('operational_person.branch_assignment_changed');
        $this->putJson('/api/v1/operational-people/'.$p['id'].'/branches', ['assignments' => []])->assertOk();
        $this->patchJson('/api/v1/operational-people/'.$p['id'].'/status', ['is_active' => false])->assertOk();
        $this->evidence('operational_person.deactivated');
    }

    public function test_calendar_changes_deletion_and_target_domain_history(): void
    {
        $url = '/api/v1/machines/'.$this->g['machine']->id.'/click-target';
        $d = ['calendar_date' => '2026-09-14', 'exception_type' => 'other', 'client_request_id' => (string) Str::uuid()];
        $row = $this->postJson($url.'/calendar-exceptions', $d)->assertCreated()->json('data');
        $this->evidence('calendar_exception.created');
        $this->postJson($url.'/calendar-exceptions', $d)->assertOk();
        $this->assertDatabaseCount('governance_audit_logs', 1);
        $this->postJson($url.'/calendar-exceptions', array_replace($d, ['exception_type' => 'store_closed', 'client_request_id' => (string) Str::uuid()]))->assertOk();
        $this->evidence('calendar_exception.updated');
        $this->deleteJson('/api/v1/calendar-exceptions/'.$row['id'])->assertOk();
        $this->assertSame('store_closed', $this->evidence('calendar_exception.deleted')['changes']['exception_type']['before']);
        $this->putJson($url, ['target_year' => 2026, 'target_month' => 9, 'monthly_click_target' => 1000, 'client_request_id' => (string) Str::uuid()])->assertSuccessful();
        $this->assertDatabaseCount('machine_click_target_revisions', 1);
        $this->assertDatabaseCount('governance_audit_logs', 3);
    }

    public function test_tenant_read_isolation_roles_and_platform_path(): void
    {
        $audit = app(GovernanceAudit::class);
        foreach (['a', 'b'] as $key) {
            $audit->record($this->g['platform'], 'account.updated', 'account', $this->g[$key]->id, $this->g[$key]->id);
        }
        $this->getJson($this->base().'/audit?account_id='.$this->g['b']->id)->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.account_id', $this->g['a']->id)->assertJsonPath('data.data.0.actor.type', 'platform');
        $this->getJson('/api/v1/accounts/'.$this->g['b']->id.'/audit')->assertForbidden();
        $this->getJson('/api/v1/platform/accounts/'.$this->g['b']->id.'/audit')->assertForbidden();
        $this->g['b']->memberships()->create(['user_id' => $this->g['aowner']->id, 'role' => 'owner', 'status' => 'active']);
        $this->getJson('/api/v1/accounts/'.$this->g['b']->id.'/audit')->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.account_id', $this->g['b']->id);
        foreach (['admin', 'technician', 'operator'] as $role) {
            $this->actingAs($this->g['a'.$role])->getJson($this->base().'/audit')->assertForbidden();
        }
        $this->actingAs($this->g['platform'])->getJson('/api/v1/platform/accounts/'.$this->g['a']->id.'/audit')->assertOk();
        $this->getJson($this->base().'/audit')->assertForbidden();
    }

    public function test_stable_bounded_pagination_legacy_metadata_and_no_mutation_routes(): void
    {
        $this->freezeTime();
        for ($i = 0; $i < 3; $i++) {
            app(GovernanceAudit::class)->record($this->g['aowner'], 'account.updated', 'account', $this->g['a']->id, $this->g['a']->id);
        }
        $ids = DB::table('governance_audit_logs')->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->all();
        $one = $this->getJson($this->base().'/audit?per_page=2')->assertOk()->json('data.data');
        $two = $this->getJson($this->base().'/audit?per_page=2&page=2')->assertOk()->json('data.data');
        $this->assertSame($ids, array_column([...$one, ...$two], 'id'));
        $this->getJson($this->base().'/audit?per_page=51')->assertUnprocessable();
        $this->getJson($this->base().'/audit?per_page=0')->assertUnprocessable();
        $this->getJson($this->base().'/audit?action=branch.created')->assertOk()->assertJsonCount(0, 'data.data');
        DB::table('governance_audit_logs')->where('id', $ids[0])->update(['metadata' => json_encode(['password' => 'do-not-expose', 'foreign_name' => 'foreign'])]);
        $this->getJson($this->base().'/audit')->assertOk()->assertDontSee('do-not-expose')->assertDontSee('foreign_name')->assertJsonPath('data.data.0.actor.type', 'unknown');
        foreach (app('router')->getRoutes() as $route) {
            if (str_contains($route->uri(), '/audit')) {
                $this->assertSame(['GET', 'HEAD'], $route->methods());
            }
        }
    }

    public static function requiredActions(): array
    {
        return array_map(fn ($action) => [$action], ['role', 'policy', 'branch', 'machine', 'person', 'supplier', 'identity', 'privilege', 'calendar']);
    }

    #[DataProvider('requiredActions')]
    public function test_required_audit_failure_rolls_back_entire_mutation(string $action): void
    {
        $this->actingAs($this->g['platform']);
        if ($action === 'calendar') {
            $this->actingAs($this->g['aowner']);
        }
        $tables = ['account_memberships', 'account_operational_permissions', 'branches', 'machines', 'operational_people', 'inventory_suppliers', 'users', 'platform_user_privileges', 'machine_operational_calendar_exceptions'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy(match ($table) {
                'account_operational_permissions' => 'account_id', 'platform_user_privileges' => 'user_id', default => 'id'
            })->get()->toJson();
        }
        $this->mock(GovernanceAudit::class)->makePartial()->shouldReceive('record')->once()->andThrow(new \RuntimeException('Synthetic audit failure'));
        $response = match ($action) {
            'role' => $this->patchJson($this->base().'/members/'.$this->g['aoperatorm']->id, ['role' => 'admin']),
            'policy' => $this->patchJson($this->base().'/settings/policy', ['operator_can_adjust_inventory' => true]),
            'branch' => $this->postJson($this->base().'/branches', ['code' => 'NEW', 'name' => 'New']),
            'machine' => $this->patchJson('/api/v1/machines/'.$this->g['machine']->id.'/status', ['status' => 'retired']),
            'person' => $this->postJson($this->base().'/operational-people', ['name' => 'New']),
            'supplier' => $this->postJson('/api/v1/inventory/suppliers', ['account_id' => $this->g['a']->id, 'code' => 'NEW', 'name' => 'New']),
            'identity' => $this->postJson($this->base().'/members/'.$this->g['aoperatorm']->id.'/password', ['password' => 'new-password', 'password_confirmation' => 'new-password']),
            'privilege' => $this->postJson('/api/v1/platform/bootstrap-superuser', ['user_id' => $this->g['aoperator']->id]),
            'calendar' => $this->postJson('/api/v1/machines/'.$this->g['machine']->id.'/click-target/calendar-exceptions', ['calendar_date' => '2026-09-14', 'exception_type' => 'other', 'client_request_id' => (string) Str::uuid()]),
        };
        $response->assertStatus(500);
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy(match ($table) {
                'account_operational_permissions' => 'account_id', 'platform_user_privileges' => 'user_id', default => 'id'
            })->get()->toJson(), $table);
        }
        $this->assertDatabaseCount('governance_audit_logs', 0);
    }

    public function test_unauthorized_mutation_has_no_audit_or_business_effect(): void
    {
        $this->actingAs($this->g['aoperator'])->patchJson($this->base().'/members/'.$this->g['aadminm']->id, ['role' => 'operator'])->assertForbidden();
        $this->assertDatabaseHas('account_memberships', ['id' => $this->g['aadminm']->id, 'role' => 'admin']);
        $this->assertDatabaseCount('governance_audit_logs', 0);
    }

    public function test_configuration_is_audited_without_lifecycle_duplication(): void
    {
        $component = ComponentCatalog::create(['code' => 'TEST', 'name' => 'Test', 'is_active' => true]);
        $url = '/api/v1/machines/'.$this->g['machine']->id.'/components';
        $mc = $this->postJson($url.'/manual', ['component_id' => $component->id, 'slot_code' => 'MANUAL', 'tracking_method' => 'counter_based', 'baseline_expected_clicks' => 1000])->assertCreated()->json('data');
        $this->evidence('machine_component.created');
        $this->patchJson('/api/v1/machine-components/'.$mc['id'], [])->assertOk();
        $this->evidence('machine_component.retired');
        $this->assertDatabaseCount('component_lifecycles', 0);
    }

    public function test_security_actions_attachment_and_raw_writer_redaction(): void
    {
        $this->actingAs($this->g['platform']);
        $id = $this->g['aoperatorm']->id;
        $this->patchJson($this->base().'/members/'.$id.'/email', ['email' => 'changed@example.test'])->assertOk();
        $this->evidence('identity.email_changed');
        $this->postJson($this->base().'/members/'.$id.'/password', ['password' => 'synthetic-password', 'password_confirmation' => 'synthetic-password'])->assertOk();
        $this->evidence('identity.credentials_reset');
        $this->postJson('/api/v1/platform/accounts/'.$this->g['a']->id.'/members', ['user_id' => $this->g['boperator']->id, 'role' => 'operator', 'branch_ids' => [$this->g['abranch']->id]])->assertCreated();
        $this->assertSame('platform', $this->evidence('membership.attached')['actor_type']);
        app(GovernanceAudit::class)->record($this->g['platform'], 'test.redaction', 'account', $this->g['a']->id, $this->g['a']->id, ['password' => 'NEVER', 'actor_type' => 'tenant', 'changes' => ['password_hash' => ['before' => 'NEVER'], 'name' => ['before' => ['session_id' => 'NEVER'], 'after' => 'Safe', 'secret' => 'NEVER']]]);
        $this->assertSame('platform', $this->evidence('test.redaction')['actor_type']);
        $this->assertStringNotContainsString('NEVER', DB::table('governance_audit_logs')->get()->toJson());
        $this->assertStringNotContainsString('synthetic-password', DB::table('governance_audit_logs')->get()->toJson());
    }

    public function test_profile_sync_exclusion_and_configuration_failure_are_atomic(): void
    {
        $model = $this->g['model'];
        $model->update(['account_id' => $this->g['a']->id]);
        $component = ComponentCatalog::create(['code' => 'D_PROFILE', 'name' => 'Profile component', 'is_active' => true]);
        $slot = $this->postJson('/api/v1/machine-models/'.$model->id.'/profiles', ['component_id' => $component->id, 'slot_code' => 'STANDARD', 'baseline_expected_clicks' => 1000])->assertCreated()->json('data');
        $this->evidence('model_profile.configuration_changed');
        $this->putJson('/api/v1/model-profile-slots/'.$slot['id'], ['baseline_expected_clicks' => 2000])->assertOk();
        $url = '/api/v1/machines/'.$this->g['machine']->id.'/components/sync';
        $this->postJson($url)->assertOk();
        $this->evidence('machine_component.profile_synced');
        $mc = DB::table('machine_components')->where('machine_id', $this->g['machine']->id)->first();
        $this->postJson('/api/v1/machine-components/'.$mc->id.'/exclude', ['reason' => 'Not fitted'])->assertNoContent();
        $this->evidence('machine_component.excluded');
        $exclusion = DB::table('machine_component_exclusions')->where('machine_id', $this->g['machine']->id)->first();
        $this->postJson('/api/v1/component-exclusions/'.$exclusion->id.'/clear')->assertNoContent();
        $this->evidence('machine_component.exclusion_cleared');
        $before = DB::table('machine_components')->where('id', $mc->id)->first();
        $count = DB::table('governance_audit_logs')->count();
        $this->mock(GovernanceAudit::class)->makePartial()->shouldReceive('record')->once()->andThrow(new \RuntimeException('Synthetic audit failure'));
        $this->postJson($url)->assertStatus(500);
        $this->assertEquals($before, DB::table('machine_components')->where('id', $mc->id)->first());
        $this->assertDatabaseCount('governance_audit_logs', $count);
    }

    public function test_contact_only_update_records_field_evidence_without_pii(): void
    {
        $data = ['account_id' => $this->g['a']->id, 'code' => 'PRIVATE', 'name' => 'Supplier'];
        $s = $this->postJson('/api/v1/inventory/suppliers', $data)->assertOk()->json('data');
        $this->putJson('/api/v1/inventory/suppliers/'.$s['id'], $data + ['email' => 'private@example.test', 'notes' => 'Private free text'])->assertOk();
        $changes = $this->evidence('supplier.updated')['changes'];
        $this->assertSame(['before' => '[not retained]', 'after' => '[not retained]'], $changes['email']);
        $this->assertSame(['before' => '[not retained]', 'after' => '[not retained]'], $changes['notes']);
        $this->assertStringNotContainsString('private@example.test', DB::table('governance_audit_logs')->get()->toJson());
        $this->assertStringNotContainsString('Private free text', DB::table('governance_audit_logs')->get()->toJson());
    }
}
