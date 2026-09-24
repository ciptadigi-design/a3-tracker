<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Branch;
use App\Models\Machine;
use App\Models\MachineErrorCode;
use App\Models\MachineModel;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceTicket;
use App\Models\Manufacturer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\MaintenanceOfficialKnowledgeFixture;
use Tests\TestCase;

class MachineMaintenanceHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $account = Account::create(['code' => 'HOME', 'name' => 'Home', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $foreignAccount = Account::create(['code' => 'OTHER', 'name' => 'Other', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $branch = Branch::create(['account_id' => $account->id, 'code' => 'MAIN', 'name' => 'Main', 'is_active' => true]);
        $foreignBranch = Branch::create(['account_id' => $foreignAccount->id, 'code' => 'OTHER', 'name' => 'Other', 'is_active' => true]);
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'C1070', 'name' => 'C1070']);
        $machine = Machine::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'A3-01', 'display_name' => 'Machine A3-01', 'status' => 'active']);
        $emptyMachine = Machine::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'A3-02', 'display_name' => 'Machine A3-02', 'status' => 'active']);
        $foreignMachine = Machine::create(['account_id' => $foreignAccount->id, 'branch_id' => $foreignBranch->id, 'machine_model_id' => $model->id, 'machine_code' => 'OTHER-01', 'display_name' => 'Other machine', 'status' => 'active']);
        $document = MaintenanceDocument::create(['account_id' => $account->id, 'manufacturer_id' => $manufacturer->id, 'machine_model_id' => $model->id, 'title' => 'Fixture Service Manual', 'document_type' => 'SERVICE_MANUAL', 'file_reference' => 'fixture.pdf', 'status' => 'PUBLISHED', 'is_active' => true]);
        $entry = MaintenanceOfficialKnowledgeFixture::c3913($document);
        $owner = $this->member($account, 'owner');

        return compact('account', 'foreignAccount', 'branch', 'foreignBranch', 'manufacturer', 'model', 'machine', 'emptyMachine', 'foreignMachine', 'document', 'entry', 'owner');
    }

    private function member(Account $account, string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active', 'accepted_at' => now()]);

        return $user;
    }

    private function ticket(array $fixture, array $attributes): MaintenanceTicket
    {
        $ticket = new MaintenanceTicket;
        $ticket->forceFill($attributes + [
            'account_id' => $fixture['account']->id,
            'branch_id' => $fixture['branch']->id,
            'machine_id' => $fixture['machine']->id,
            'type' => 'breakdown',
            'title' => 'Maintenance Ticket',
            'priority' => 'normal',
            'status' => 'OPEN',
            'opened_at' => '2026-09-24 07:32:00',
        ]);
        $ticket->save();

        return $ticket;
    }

    public function test_authorized_history_contains_official_manual_and_legacy_tickets_in_deterministic_order(): void
    {
        $f = $this->fixture();
        $legacyCode = MachineErrorCode::create(['account_id' => $f['account']->id, 'machine_model_id' => $f['model']->id, 'code' => 'C-C152', 'title' => 'Legacy-compatible error', 'severity' => 'warning', 'is_active' => true]);
        $manual = $this->ticket($f, ['id' => '00000000-0000-4000-8000-000000000001', 'title' => 'Manual incident', 'description' => 'Suara tidak normal dari area finishing.', 'opened_at' => '2026-09-22 02:14:00']);
        $legacy = $this->ticket($f, ['id' => '00000000-0000-4000-8000-000000000002', 'error_code_id' => $legacyCode->id, 'title' => 'Legacy incident', 'opened_at' => '2026-09-24 07:32:00']);
        $official = $this->ticket($f, ['id' => '00000000-0000-4000-8000-000000000003', 'official_error_entry_id' => $f['entry']->id, 'title' => 'C-3913 incident', 'description' => 'Error muncul saat proses cetak.', 'status' => 'DONE', 'opened_at' => '2026-09-24 07:32:00', 'started_at' => '2026-09-24 08:00:00', 'resolved_at' => '2026-09-24 09:00:00']);

        $response = $this->actingAs($f['owner'])->getJson("/api/v1/maintenance/machines/{$f['machine']->id}/history")
            ->assertOk()
            ->assertJsonPath('data.summary.total', 3)
            ->assertJsonPath('data.summary.active', 2)
            ->assertJsonPath('data.summary.completed', 1)
            ->assertJsonPath('data.tickets.per_page', 10)
            ->assertJsonPath('data.tickets.data.0.id', $official->id)
            ->assertJsonPath('data.tickets.data.0.official_error_entry.code', 'C-3913')
            ->assertJsonPath('data.tickets.data.0.official_error_entry.classification', 'Main body: Fusing unit placement abnormality')
            ->assertJsonPath('data.tickets.data.0.official_error_entry.document_title', 'Fixture Service Manual')
            ->assertJsonPath('data.tickets.data.0.status', 'DONE')
            ->assertJsonPath('data.tickets.data.1.id', $legacy->id)
            ->assertJsonPath('data.tickets.data.1.error_code.code', 'C-C152')
            ->assertJsonPath('data.tickets.data.2.id', $manual->id)
            ->assertJsonPath('data.tickets.data.2.official_error_entry', null);

        $payload = $response->json('data.tickets.data.0');
        $this->assertArrayNotHasKey('official_error_entry_id', $payload);
        $this->assertArrayNotHasKey('raw_source_text', $payload['official_error_entry']);
        $this->assertArrayNotHasKey('steps', $payload['official_error_entry']);
        $this->assertArrayNotHasKey('cause', $payload['official_error_entry']);
    }

    public function test_history_is_empty_and_status_filter_uses_actual_ticket_lifecycle(): void
    {
        $f = $this->fixture();
        $this->ticket($f, ['status' => 'OPEN']);
        $this->ticket($f, ['status' => 'CANCELLED', 'resolved_at' => now()]);

        $this->actingAs($f['owner'])->getJson("/api/v1/maintenance/machines/{$f['machine']->id}/history?status=CANCELLED")
            ->assertOk()
            ->assertJsonCount(1, 'data.tickets.data')
            ->assertJsonPath('data.tickets.data.0.status', 'CANCELLED');
        $this->actingAs($f['owner'])->getJson("/api/v1/maintenance/machines/{$f['emptyMachine']->id}/history")
            ->assertOk()
            ->assertJsonPath('data.summary.total', 0)
            ->assertJsonCount(0, 'data.tickets.data');
    }

    public function test_history_uses_sql_pagination(): void
    {
        $f = $this->fixture();
        foreach (range(1, 12) as $index) {
            $this->ticket($f, ['title' => "Incident {$index}", 'opened_at' => now()->subMinutes($index)]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($f['owner'])->getJson("/api/v1/maintenance/machines/{$f['machine']->id}/history?per_page=10&page=2")
            ->assertOk()
            ->assertJsonPath('data.tickets.total', 12)
            ->assertJsonPath('data.tickets.current_page', 2)
            ->assertJsonCount(2, 'data.tickets.data');

        $queries = DB::getQueryLog();
        $this->assertTrue(collect($queries)->contains(fn (array $query) => str_contains($query['query'], 'maintenance_tickets') && str_contains(strtolower($query['query']), 'limit')));
        $this->assertLessThan(15, count($queries), 'History relationship queries must stay bounded rather than grow per ticket.');
    }

    public function test_machine_authorization_and_tenant_isolation_are_enforced(): void
    {
        $f = $this->fixture();
        $unassignedMember = $this->member($f['account'], 'member');
        $foreignOwner = $this->member($f['foreignAccount'], 'owner');

        $this->actingAs($unassignedMember)->getJson("/api/v1/maintenance/machines/{$f['machine']->id}/history")->assertForbidden();
        $this->actingAs($foreignOwner)->getJson("/api/v1/maintenance/machines/{$f['machine']->id}/history")->assertForbidden();
        $this->actingAs($f['owner'])->getJson("/api/v1/maintenance/machines/{$f['foreignMachine']->id}/history")->assertForbidden();
    }

    public function test_cross_account_knowledge_is_hidden_even_for_multi_membership_user_while_ticket_remains_visible(): void
    {
        $f = $this->fixture();
        $foreignDocument = MaintenanceDocument::create(['account_id' => $f['foreignAccount']->id, 'manufacturer_id' => $f['manufacturer']->id, 'machine_model_id' => $f['model']->id, 'title' => 'Foreign Manual', 'document_type' => 'SERVICE_MANUAL', 'file_reference' => 'foreign.pdf', 'status' => 'PUBLISHED', 'is_active' => true]);
        $foreignEntry = MaintenanceOfficialKnowledgeFixture::c3913($foreignDocument);
        AccountMembership::create(['account_id' => $f['foreignAccount']->id, 'user_id' => $f['owner']->id, 'role' => 'owner', 'status' => 'active', 'accepted_at' => now()]);
        $ticket = $this->ticket($f, ['official_error_entry_id' => $foreignEntry->id, 'title' => 'Safe historical incident']);

        $this->actingAs($f['owner'])->getJson("/api/v1/maintenance/machines/{$f['machine']->id}/history")
            ->assertOk()
            ->assertJsonPath('data.tickets.data.0.id', $ticket->id)
            ->assertJsonPath('data.tickets.data.0.title', 'Safe historical incident')
            ->assertJsonPath('data.tickets.data.0.official_error_entry', null)
            ->assertJsonMissing(['document_title' => 'Foreign Manual']);
        $this->actingAs($f['owner'])->getJson("/api/v1/maintenance/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('data.official_error_entry', null)
            ->assertJsonMissing(['title' => 'Foreign Manual']);
    }
}
