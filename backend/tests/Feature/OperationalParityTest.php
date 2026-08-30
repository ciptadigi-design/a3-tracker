<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Machine;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\OperationalPerson;
use App\Models\OperationalPersonBranch;
use App\Models\User;
use App\Services\CreateCounterReading;
use App\Services\MachineTimezoneResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationalParityTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $a = Account::create(['code' => 'OPS', 'name' => 'Ops', 'default_timezone' => 'Asia/Jakarta']);
        $b = $a->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'timezone' => 'Asia/Jakarta']);
        $u = User::factory()->create(['status' => 'active']);
        $m = AccountMembership::create(['account_id' => $a->id, 'user_id' => $u->id, 'role' => 'owner', 'status' => 'active']);
        AccountMembershipBranch::create(['account_id' => $a->id, 'membership_id' => $m->id, 'branch_id' => $b->id, 'is_active' => true]);
        $man = Manufacturer::create(['code' => 'km', 'name' => 'Konica Minolta']);
        $mod = MachineModel::create(['manufacturer_id' => $man->id, 'model_code' => 'c1070', 'name' => 'C1070']);
        $machine = Machine::create(['account_id' => $a->id, 'branch_id' => $b->id, 'machine_model_id' => $mod->id, 'machine_code' => 'CG-TUP-A3-01', 'display_name' => 'C1070', 'status' => 'active']);
        $p = OperationalPerson::create(['account_id' => $a->id, 'name' => 'Old Name', 'is_active' => true]);
        OperationalPersonBranch::create(['account_id' => $a->id, 'person_id' => $p->id, 'branch_id' => $b->id, 'is_active' => true]);

        return compact('a', 'b', 'u', 'machine', 'p');
    }

    public function test_counter_is_monotonic_idempotent_and_snapshot_is_immutable(): void
    {
        $f = $this->fixture();
        $s = app(CreateCounterReading::class);
        $base = ['operator_person_id' => $f['p']->id, 'shift_code' => 'S1', 'notes' => null, 'client_request_id' => (string) Str::uuid()];
        $one = $s->execute($f['u'], $f['machine'], array_merge($base, ['reading_value' => 100, 'observed_at' => '2026-08-31T00:30:00+07:00']));
        $secondRequest = (string) Str::uuid();
        $two = $s->execute($f['u'], $f['machine'], array_merge($base, ['reading_value' => 150, 'observed_at' => '2026-08-31T01:30:00+07:00', 'client_request_id' => $secondRequest]));
        $this->assertNull($one->previous_reading_id);
        $this->assertSame((string) $one->id, (string) $two->previous_reading_id);
        $this->assertSame('Old Name', $two->operator_name_snapshot);
        $f['p']->update(['name' => 'New Name']);
        $this->assertSame('Old Name', $two->fresh()->operator_name_snapshot);
        $this->assertSame((string) $two->id, (string) $s->execute($f['u'], $f['machine'], array_merge($base, ['reading_value' => 150, 'observed_at' => '2026-08-31T01:30:00+07:00', 'client_request_id' => $two->client_request_id]))->id);
        $this->expectException('Symfony\\Component\\HttpKernel\\Exception\\ConflictHttpException');
        $s->execute($f['u'], $f['machine'], array_merge($base, ['reading_value' => 140, 'observed_at' => '2026-08-31T02:00:00+07:00', 'client_request_id' => (string) Str::uuid()]));
    }

    public function test_timezone_fallback_and_period_usage_use_local_day(): void
    {
        $f = $this->fixture();
        $this->assertSame('Asia/Jakarta', app(MachineTimezoneResolver::class)->resolve($f['machine']));
        $this->actingAs($f['u'])->postJson('/api/v1/machines/'.$f['machine']->id.'/counters', ['reading_value' => 10, 'observed_at' => '2026-08-30T17:30:00Z', 'operator_person_id' => $f['p']->id, 'client_request_id' => (string) Str::uuid()])->assertCreated();
        $this->actingAs($f['u'])->postJson('/api/v1/machines/'.$f['machine']->id.'/counters', ['reading_value' => 25, 'observed_at' => '2026-08-31T01:00:00+07:00', 'operator_person_id' => $f['p']->id, 'client_request_id' => (string) Str::uuid()])->assertCreated();
        $this->actingAs($f['u'])->getJson('/api/v1/machines/'.$f['machine']->id.'/counters/period?from=2026-08-31&to=2026-08-31')->assertOk()->assertJsonPath('data.usage', 15);
    }
}
