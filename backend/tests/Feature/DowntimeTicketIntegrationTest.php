<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Branch;
use App\Models\DowntimeEvent;
use App\Models\Machine;
use App\Models\MachineModel;
use App\Models\MaintenanceTicket;
use App\Models\Manufacturer;
use App\Models\User;
use App\Services\DowntimeService;
use App\Services\MaintenanceTicketService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DowntimeTicketIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $account = Account::create(['code' => 'HOME', 'name' => 'Home', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $foreignAccount = Account::create(['code' => 'OTHER', 'name' => 'Other', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $branch = Branch::create(['account_id' => $account->id, 'code' => 'MAIN', 'name' => 'Main', 'timezone' => 'Asia/Jakarta', 'is_active' => true]);
        $otherBranch = Branch::create(['account_id' => $account->id, 'code' => 'SECOND', 'name' => 'Second', 'timezone' => 'Asia/Jakarta', 'is_active' => true]);
        $foreignBranch = Branch::create(['account_id' => $foreignAccount->id, 'code' => 'OTHER', 'name' => 'Other', 'timezone' => 'Asia/Jakarta', 'is_active' => true]);
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'C1070', 'name' => 'C1070']);
        $machine = Machine::create(['account_id' => $account->id, 'branch_id' => $branch->id, 'machine_model_id' => $model->id, 'machine_code' => 'A3-01', 'display_name' => 'Machine A3-01', 'status' => 'active']);
        $otherMachine = Machine::create(['account_id' => $account->id, 'branch_id' => $otherBranch->id, 'machine_model_id' => $model->id, 'machine_code' => 'A3-02', 'display_name' => 'Machine A3-02', 'status' => 'active']);
        $foreignMachine = Machine::create(['account_id' => $foreignAccount->id, 'branch_id' => $foreignBranch->id, 'machine_model_id' => $model->id, 'machine_code' => 'F-01', 'display_name' => 'Foreign', 'status' => 'active']);
        $owner = $this->member($account, 'owner');

        return compact('account', 'foreignAccount', 'branch', 'otherBranch', 'foreignBranch', 'machine', 'otherMachine', 'foreignMachine', 'owner');
    }

    private function member(Account $account, string $role, ?Branch $branch = null): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $membership = AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active', 'accepted_at' => now()]);
        if ($branch && $role !== 'owner') {
            AccountMembershipBranch::create(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $branch->id, 'is_active' => true]);
        }

        return $user;
    }

    private function start(array $fixture, array $overrides = []): array
    {
        return $this->actingAs($fixture['owner'])->postJson("/api/v1/downtime/machines/{$fixture['machine']->id}/start", $overrides + [
            'cause' => 'hasil_kotor',
            'description' => 'Hasil cetak memiliki noda.',
            'error_code_text' => 'C-3913',
            'client_request_id' => (string) Str::uuid(),
        ])->assertCreated()->json('data');
    }

    public function test_relationship_migration_rolls_back_without_deleting_existing_records_and_reapplies(): void
    {
        $f = $this->fixture();
        $historical = DowntimeEvent::create([
            'account_id' => $f['account']->id, 'branch_id' => $f['branch']->id, 'machine_id' => $f['machine']->id,
            'cause' => 'mechanical', 'actual_start_at' => now()->subDay(), 'actual_end_at' => now()->subHours(20), 'created_by' => $f['owner']->id,
        ]);
        $manual = MaintenanceTicket::create([
            'account_id' => $f['account']->id, 'branch_id' => $f['branch']->id, 'machine_id' => $f['machine']->id,
            'title' => 'Existing manual ticket', 'reported_by' => $f['owner']->id,
        ]);
        $migration = require database_path('migrations/2026_10_19_000100_link_downtime_events_to_maintenance_tickets.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('maintenance_tickets', 'downtime_event_id'));
        $this->assertDatabaseHas('downtime_events', ['id' => $historical->id]);
        $this->assertDatabaseHas('maintenance_tickets', ['id' => $manual->id]);

        $migration->up();
        $this->assertTrue(Schema::hasColumn('maintenance_tickets', 'downtime_event_id'));
        $this->assertDatabaseHas('downtime_events', ['id' => $historical->id]);
        $this->assertDatabaseHas('maintenance_tickets', ['id' => $manual->id, 'downtime_event_id' => null]);
    }

    public function test_new_downtime_atomically_creates_one_mapped_ticket_and_audits_both(): void
    {
        $f = $this->fixture();
        $event = $this->start($f);

        $this->assertNotNull($event['maintenance_ticket']['id']);
        $ticket = MaintenanceTicket::findOrFail($event['maintenance_ticket']['id']);
        $this->assertSame($event['id'], $ticket->downtime_event_id);
        $this->assertSame((string) $f['machine']->id, $ticket->machine_id);
        $this->assertSame('Hasil Kotor', $ticket->title);
        $this->assertSame('Hasil cetak memiliki noda.', $ticket->description);
        $this->assertSame('OPEN', $ticket->status);
        $this->assertNull($ticket->assigned_to);
        $this->assertTrue($ticket->opened_at->equalTo(DowntimeEvent::findOrFail($event['id'])->actual_start_at));
        $this->assertDatabaseHas('governance_audit_logs', ['action' => 'downtime_event.started', 'target_id' => $event['id']]);
        $this->assertDatabaseHas('governance_audit_logs', ['action' => 'maintenance_ticket.created_from_downtime', 'target_id' => $ticket->id]);
    }

    public function test_ticket_failure_rolls_back_the_downtime_event(): void
    {
        $f = $this->fixture();
        $this->app->bind(MaintenanceTicketService::class, fn () => new class extends MaintenanceTicketService
        {
            public function create(array $d): MaintenanceTicket
            {
                throw new RuntimeException('simulated ticket failure');
            }
        });

        try {
            app(DowntimeService::class)->start($f['machine'], $f['owner'], ['cause' => 'paper_jam', 'client_request_id' => (string) Str::uuid()], true);
            $this->fail('Expected ticket creation to fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated ticket failure', $e->getMessage());
        }

        $this->assertDatabaseCount('downtime_events', 0);
        $this->assertDatabaseCount('maintenance_tickets', 0);
    }

    public function test_repeated_request_is_idempotent_and_never_duplicates_the_ticket(): void
    {
        $f = $this->fixture();
        $requestId = (string) Str::uuid();
        $payload = ['cause' => 'paper_jam', 'client_request_id' => $requestId];
        $first = $this->actingAs($f['owner'])->postJson("/api/v1/downtime/machines/{$f['machine']->id}/start", $payload)->assertCreated()->json('data');
        $retry = $this->actingAs($f['owner'])->postJson("/api/v1/downtime/machines/{$f['machine']->id}/start", $payload)->assertCreated()->json('data');

        $this->assertSame($first['id'], $retry['id']);
        $this->assertSame($first['maintenance_ticket']['id'], $retry['maintenance_ticket']['id']);
        $this->assertDatabaseCount('downtime_events', 1);
        $this->assertDatabaseCount('maintenance_tickets', 1);
    }

    public function test_downtime_and_ticket_status_lifecycles_are_independent(): void
    {
        $f = $this->fixture();
        $first = $this->start($f);
        $ticketId = $first['maintenance_ticket']['id'];
        $this->actingAs($f['owner'])->postJson("/api/v1/downtime/events/{$first['id']}/end", [])->assertOk();
        $this->assertSame('OPEN', MaintenanceTicket::findOrFail($ticketId)->status);

        $second = $this->start($f);
        $secondTicketId = $second['maintenance_ticket']['id'];
        $this->actingAs($f['owner'])->patchJson("/api/v1/maintenance/tickets/{$secondTicketId}/status", ['status' => 'IN_PROGRESS'])->assertOk();
        $this->actingAs($f['owner'])->patchJson("/api/v1/maintenance/tickets/{$secondTicketId}/status", ['status' => 'DONE', 'resolution_cause' => 'Roller kotor', 'resolution_action' => 'Roller dibersihkan', 'resolution_test_result' => 'normal'])->assertOk();
        $this->assertTrue(DowntimeEvent::findOrFail($second['id'])->isActive());
    }

    public function test_historical_downtime_and_manual_tickets_remain_valid_without_a_link(): void
    {
        $f = $this->fixture();
        $historical = DowntimeEvent::create([
            'account_id' => $f['account']->id, 'branch_id' => $f['branch']->id, 'machine_id' => $f['machine']->id,
            'cause' => 'mechanical', 'actual_start_at' => now()->subDay(), 'actual_end_at' => now()->subHours(20), 'created_by' => $f['owner']->id,
        ]);
        $manual = MaintenanceTicket::create([
            'account_id' => $f['account']->id, 'branch_id' => $f['branch']->id, 'machine_id' => $f['machine']->id,
            'title' => 'Manual inspection', 'reported_by' => $f['owner']->id,
        ]);

        $this->assertNull($historical->maintenanceTicket);
        $this->assertNull($manual->downtimeEvent);
        $this->actingAs($f['owner'])->getJson('/api/v1/downtime/events')->assertOk()->assertJsonPath('data.data.0.maintenance_ticket', null);
        $this->actingAs($f['owner'])->getJson("/api/v1/maintenance/tickets/{$manual->id}")->assertOk()->assertJsonPath('data.downtime_event', null);
    }

    public function test_database_rejects_cross_branch_and_cross_account_links(): void
    {
        $f = $this->fixture();
        $event = DowntimeEvent::create([
            'account_id' => $f['account']->id, 'branch_id' => $f['branch']->id, 'machine_id' => $f['machine']->id,
            'cause' => 'paper_jam', 'actual_start_at' => now(), 'created_by' => $f['owner']->id, 'active_key' => 'active',
        ]);

        foreach ([
            [$f['account']->id, $f['otherBranch']->id, $f['otherMachine']->id],
            [$f['foreignAccount']->id, $f['foreignBranch']->id, $f['foreignMachine']->id],
        ] as [$accountId, $branchId, $machineId]) {
            try {
                MaintenanceTicket::create(['account_id' => $accountId, 'branch_id' => $branchId, 'machine_id' => $machineId, 'downtime_event_id' => $event->id, 'title' => 'Invalid link']);
                $this->fail('Expected scoped foreign key rejection.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_ticket_list_is_scoped_to_the_users_authorized_branches(): void
    {
        $f = $this->fixture();
        $technician = $this->member($f['account'], 'technician', $f['branch']);
        $visible = MaintenanceTicket::create([
            'account_id' => $f['account']->id, 'branch_id' => $f['branch']->id, 'machine_id' => $f['machine']->id,
            'title' => 'Visible ticket', 'reported_by' => $f['owner']->id,
        ]);
        $hidden = MaintenanceTicket::create([
            'account_id' => $f['account']->id, 'branch_id' => $f['otherBranch']->id, 'machine_id' => $f['otherMachine']->id,
            'title' => 'Other branch ticket', 'reported_by' => $f['owner']->id,
        ]);

        $ids = collect($this->actingAs($technician)->getJson('/api/v1/maintenance/tickets')->assertOk()->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($visible->id));
        $this->assertFalse($ids->contains($hidden->id));
    }

    public function test_downtime_permission_can_create_the_automatic_ticket_without_ticket_create_permission(): void
    {
        $f = $this->fixture();
        $operator = $this->member($f['account'], 'operator', $f['branch']);
        DB::table('account_operational_permissions')->insert(['account_id' => $f['account']->id, 'operator_can_manage_downtime' => true]);

        $this->actingAs($operator)->postJson("/api/v1/downtime/machines/{$f['machine']->id}/start", ['cause' => 'paper_jam'])
            ->assertCreated()
            ->assertJsonPath('data.maintenance_ticket.status', 'OPEN');
        $this->assertDatabaseCount('maintenance_tickets', 1);
    }

    public function test_automatic_ticket_does_not_change_downtime_analytics_counts(): void
    {
        $f = $this->fixture();
        $this->start($f);
        $today = now('Asia/Jakarta')->format('Y-m-d');

        $this->actingAs($f['owner'])->getJson("/api/v1/downtime/overview?account_id={$f['account']->id}&period_start={$today}&period_end={$today}")
            ->assertOk()
            ->assertJsonPath('data.event_count', 1)
            ->assertJsonPath('data.ongoing_event_count', 1);
    }
}
