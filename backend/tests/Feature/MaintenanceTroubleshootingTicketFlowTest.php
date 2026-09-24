<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Branch;
use App\Models\Machine;
use App\Models\MachineModel;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceOfficialErrorEntry;
use App\Models\MaintenanceTicket;
use App\Models\Manufacturer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\MaintenanceOfficialKnowledgeFixture;
use Tests\TestCase;

class MaintenanceTroubleshootingTicketFlowTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $account = Account::create(['code' => 'HOME', 'name' => 'Home', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $foreign = Account::create(['code' => 'OTHER', 'name' => 'Other', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $branch = Branch::create(['account_id' => $account->id, 'code' => 'MAIN', 'name' => 'Main', 'is_active' => true]);
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'C1070', 'name' => 'C1070']);
        $otherModel = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'OTHER', 'name' => 'Other Model']);
        $machine = Machine::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'A3-01', 'display_name' => 'Machine A3-01', 'status' => 'active']);
        $document = MaintenanceDocument::create(['account_id' => $account->id, 'manufacturer_id' => $manufacturer->id, 'machine_model_id' => $model->id, 'title' => 'Fixture Service Manual', 'document_type' => 'SERVICE_MANUAL', 'file_reference' => 'fixture.pdf', 'status' => 'PUBLISHED', 'is_active' => true]);
        $entry = MaintenanceOfficialKnowledgeFixture::c3913($document);
        $owner = $this->member($account);

        return compact('account', 'foreign', 'branch', 'manufacturer', 'model', 'otherModel', 'machine', 'document', 'entry', 'owner');
    }

    private function member(Account $account): User
    {
        $user = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active', 'accepted_at' => now()]);

        return $user;
    }

    public function test_nullable_restrictive_official_reference_is_additive(): void
    {
        $this->assertTrue(Schema::hasColumn('maintenance_tickets', 'official_error_entry_id'));
        $index = collect(Schema::getIndexes('maintenance_tickets'))->firstWhere('name', 'mt_official_error_entry_idx');
        $this->assertNotNull($index);

        $f = $this->fixture();
        $ticket = $this->actingAs($f['owner'])->postJson('/api/v1/maintenance/tickets', [
            'machine_id' => $f['machine']->id,
            'title' => 'Manual ticket',
            'description' => 'Technician-controlled observation.',
        ])->assertCreated()->assertJsonPath('data.official_error_entry_id', null)->json('data');

        $this->assertNull(MaintenanceTicket::findOrFail($ticket['id'])->official_error_entry_id);
    }

    public function test_authorized_machine_and_official_entry_are_linked_without_copying_manual_content(): void
    {
        $f = $this->fixture();
        $description = 'Error repeats after warm-up; technician inspected the connector.';
        $ticket = $this->actingAs($f['owner'])->postJson('/api/v1/maintenance/tickets', [
            'machine_id' => $f['machine']->id,
            'official_error_entry_id' => $f['entry']->id,
            'title' => 'C-3913 · placement abnormality',
            'description' => $description,
        ])->assertCreated()
            ->assertJsonPath('data.machine_id', (string) $f['machine']->id)
            ->assertJsonPath('data.official_error_entry_id', (string) $f['entry']->id)
            ->assertJsonPath('data.description', $description)
            ->json('data');

        $reference = $this->actingAs($f['owner'])->getJson("/api/v1/maintenance/tickets/{$ticket['id']}")
            ->assertOk()
            ->assertJsonPath('data.official_error_entry.code', 'C-3913')
            ->assertJsonPath('data.official_error_entry.document.title', 'Fixture Service Manual')
            ->json('data.official_error_entry');
        $this->assertArrayNotHasKey('raw_source_text', $reference);
        $this->assertArrayNotHasKey('steps', $reference);
        $this->assertArrayNotHasKey('assisted_versions', $reference);
    }

    public function test_possible_accessory_variant_is_allowed_without_asserting_a_match(): void
    {
        $f = $this->fixture();
        [$possible] = MaintenanceOfficialKnowledgeFixture::c1103Variants($f['document']);

        $this->actingAs($f['owner'])->postJson('/api/v1/maintenance/tickets', [
            'machine_id' => $f['machine']->id,
            'official_error_entry_id' => $possible->id,
            'title' => 'C-1103 follow-up',
        ])->assertCreated()->assertJsonPath('data.official_error_entry_id', (string) $possible->id);

        $this->assertFalse(Schema::hasColumn('maintenance_tickets', 'official_knowledge_match'));
    }

    public function test_model_mismatch_requires_explicit_acknowledgement(): void
    {
        $f = $this->fixture();
        $mismatch = MaintenanceOfficialErrorEntry::create(['document_id' => $f['document']->id, 'code' => 'C-9998', 'variant_key' => 'OTHER_MODEL', 'classification' => 'Other model only', 'raw_source_text' => 'Fixture source.']);
        $mismatch->applicabilities()->create(['sequence' => 1, 'scope_type' => 'MAIN_BODY', 'scope_label' => 'Other Model', 'machine_model_id' => $f['otherModel']->id]);
        $payload = ['machine_id' => $f['machine']->id, 'official_error_entry_id' => $mismatch->id, 'title' => 'Manual exception'];

        $this->actingAs($f['owner'])->postJson('/api/v1/maintenance/tickets', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['official_error_entry_id']);
        $this->actingAs($f['owner'])->postJson('/api/v1/maintenance/tickets', $payload + ['confirm_not_applicable' => true])
            ->assertCreated()->assertJsonPath('data.official_error_entry_id', (string) $mismatch->id);
    }

    public function test_cross_tenant_machine_and_knowledge_parameters_are_rejected(): void
    {
        $f = $this->fixture();
        $attacker = $this->member($f['foreign']);
        $this->actingAs($attacker)->postJson('/api/v1/maintenance/tickets', ['machine_id' => $f['machine']->id, 'official_error_entry_id' => $f['entry']->id, 'title' => 'Forged'])->assertForbidden();

        $foreignDocument = MaintenanceDocument::create(['account_id' => $f['foreign']->id, 'manufacturer_id' => $f['manufacturer']->id, 'machine_model_id' => $f['model']->id, 'title' => 'Foreign Manual', 'document_type' => 'SERVICE_MANUAL', 'file_reference' => 'foreign.pdf', 'status' => 'PUBLISHED', 'is_active' => true]);
        $foreignEntry = MaintenanceOfficialKnowledgeFixture::c3913($foreignDocument);
        AccountMembership::create(['account_id' => $f['foreign']->id, 'user_id' => $f['owner']->id, 'role' => 'owner', 'status' => 'active', 'accepted_at' => now()]);
        $this->actingAs($f['owner'])->postJson('/api/v1/maintenance/tickets', ['machine_id' => $f['machine']->id, 'official_error_entry_id' => $foreignEntry->id, 'title' => 'Forged knowledge'])->assertNotFound();
    }

    public function test_reference_survives_ticket_lifecycle_and_restricts_official_deletion(): void
    {
        $f = $this->fixture();
        $ticket = $this->actingAs($f['owner'])->postJson('/api/v1/maintenance/tickets', ['machine_id' => $f['machine']->id, 'official_error_entry_id' => $f['entry']->id, 'title' => 'Lifecycle'])->json('data');
        $this->actingAs($f['owner'])->patchJson("/api/v1/maintenance/tickets/{$ticket['id']}/status", ['status' => 'IN_PROGRESS'])->assertOk();
        $this->actingAs($f['owner'])->patchJson("/api/v1/maintenance/tickets/{$ticket['id']}/status", ['status' => 'DONE'])->assertOk();
        $this->assertSame((string) $f['entry']->id, MaintenanceTicket::findOrFail($ticket['id'])->official_error_entry_id);

        $this->expectException(QueryException::class);
        $f['entry']->delete();
    }
}
