<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\ComponentCatalog;
use App\Models\ComponentLifecycle;
use App\Models\Machine;
use App\Models\MachineComponent;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\AccountMembership;
use App\Models\ModelProfile;
use App\Models\ModelProfileSlot;
use App\Models\User;
use App\Services\ComponentConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * Production incident: "Initialize Lifecycle -> Initialize as of current
 * counter" returned Conflict for a component (DRUM_K, Inherited assignment)
 * that had never had a lifecycle record before. Root cause: the frontend sent
 * no started_at/installed_counter for this mode, so a prior silent success
 * created a status='unknown' lifecycle with installed_counter=null - which
 * both the backend's configuration_state and the frontend's lifecycle_status
 * classify identically to "zero history" (see ComponentsController::
 * machineComponents() and laravelComponentProjection.js), inviting a retry
 * that correctly hit the existing duplicate guard. The fix makes "Initialize
 * as of current counter" actually carry a real started_at + installed_counter
 * so it lands as a true active lifecycle (never leaves such a stub behind).
 * These tests hold the domain guard itself to the letter of that fix.
 */
class M2_21_FirstLifecycleInitializationTest extends TestCase
{
    use RefreshDatabase;

    private function graph(): array
    {
        $a = Account::create(['code' => 'M2_21', 'name' => 'M2.21 Lifecycle']);
        $b = Branch::create(['account_id' => $a->id, 'code' => 'MAIN', 'name' => 'Main']);
        $man = Manufacturer::create(['code' => 'KM', 'name' => 'Konica']);
        $model = MachineModel::create(['manufacturer_id' => $man->id, 'model_code' => 'C1070', 'name' => 'C1070']);
        $drum = ComponentCatalog::create(['code' => 'DRUM_K', 'name' => 'Drum K']);
        $profile = ModelProfile::create(['machine_model_id' => $model->id, 'name' => 'C1070 profile']);
        $slot = ModelProfileSlot::create(['profile_id' => $profile->id, 'component_id' => $drum->id, 'slot_code' => 'DRUM_K', 'display_order' => 0, 'tracking_method' => 'counter_based', 'baseline_expected_clicks' => 1_000_000]);
        $machine = Machine::create(['account_id' => $a->id, 'branch_id' => $b->id, 'machine_model_id' => $model->id, 'machine_code' => 'CG-TUP-A3-01', 'display_name' => 'CG-TUP-A3-01']);
        $otherAccount = Account::create(['code' => 'M2_21_OTHER', 'name' => 'Other Tenant']);
        $otherBranch = Branch::create(['account_id' => $otherAccount->id, 'code' => 'MAIN', 'name' => 'Main']);
        $otherMachine = Machine::create(['account_id' => $otherAccount->id, 'branch_id' => $otherBranch->id, 'machine_model_id' => $model->id, 'machine_code' => 'OTHER', 'display_name' => 'OTHER']);

        return compact('a', 'b', 'drum', 'profile', 'slot', 'machine', 'otherAccount', 'otherMachine');
    }

    // A. First lifecycle: zero history, inherited assignment, current counter known.
    public function test_first_lifecycle_initializes_as_active_with_the_authoritative_counter(): void
    {
        $f = $this->graph();
        $s = app(ComponentConfigurationService::class);
        $s->sync($f['machine']);
        $mc = MachineComponent::where('machine_id', $f['machine']->id)->where('slot_code', 'DRUM_K')->first();
        $this->assertSame(0, $mc->lifecycles()->count());

        $startedAt = now();
        $life = $s->initialize($mc, ['started_at' => $startedAt, 'installed_counter' => 1_505_159, 'client_request_id' => (string) Str::uuid()]);

        $this->assertSame('active', $life->status);
        $this->assertSame('1505159.0000', (string) $life->installed_counter);
        $this->assertSame(1, ComponentLifecycle::where('machine_component_id', $mc->id)->count());
        $this->assertSame(1, ComponentLifecycle::where('machine_component_id', $mc->id)->where('status', 'active')->count());
    }

    // B. An existing active lifecycle blocks a second initialization.
    public function test_existing_active_lifecycle_rejects_reinitialization(): void
    {
        $f = $this->graph();
        $s = app(ComponentConfigurationService::class);
        $s->sync($f['machine']);
        $mc = MachineComponent::where('machine_id', $f['machine']->id)->where('slot_code', 'DRUM_K')->first();
        $s->initialize($mc, ['started_at' => now(), 'installed_counter' => 1_000_000, 'client_request_id' => (string) Str::uuid()]);

        $this->expectException(ConflictHttpException::class);
        $s->initialize($mc, ['started_at' => now(), 'installed_counter' => 1_100_000, 'client_request_id' => (string) Str::uuid()]);
    }

    // C. Duplicate / concurrent initialization (two distinct requests racing
    // the same component) is rejected safely and leaves exactly one lifecycle.
    public function test_concurrent_initialization_attempts_leave_exactly_one_lifecycle(): void
    {
        $f = $this->graph();
        $s = app(ComponentConfigurationService::class);
        $s->sync($f['machine']);
        $mc = MachineComponent::where('machine_id', $f['machine']->id)->where('slot_code', 'DRUM_K')->first();

        $s->initialize($mc, ['started_at' => now(), 'installed_counter' => 1_505_159, 'client_request_id' => (string) Str::uuid()]);
        try {
            $s->initialize($mc, ['started_at' => now(), 'installed_counter' => 1_505_159, 'client_request_id' => (string) Str::uuid()]);
            $this->fail('second, independently-keyed concurrent initialization should be rejected');
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }
        $this->assertSame(1, ComponentLifecycle::where('machine_component_id', $mc->id)->count());
    }

    // C2. The exact production shape of a double-submit: the SAME
    // client_request_id replays idempotently instead of creating a duplicate.
    public function test_duplicate_submission_with_the_same_request_id_replays_instead_of_duplicating(): void
    {
        $f = $this->graph();
        $s = app(ComponentConfigurationService::class);
        $s->sync($f['machine']);
        $mc = MachineComponent::where('machine_id', $f['machine']->id)->where('slot_code', 'DRUM_K')->first();
        $id = (string) Str::uuid();
        $startedAt = now()->startOfSecond();

        $first = $s->initialize($mc, ['started_at' => $startedAt, 'installed_counter' => 1_505_159, 'client_request_id' => $id]);
        $second = $s->initialize($mc, ['started_at' => $startedAt, 'installed_counter' => 1_505_159, 'client_request_id' => $id]);

        $this->assertSame((string) $first->id, (string) $second->id);
        $this->assertSame(1, ComponentLifecycle::where('machine_component_id', $mc->id)->count());
    }

    // D. A closed historical lifecycle with no active lifecycle still allows
    // a fresh initialization - absence of an ACTIVE lifecycle is what matters,
    // not the absence of ANY lifecycle row.
    public function test_closed_historical_lifecycle_with_no_active_lifecycle_allows_new_initialization(): void
    {
        $f = $this->graph();
        $s = app(ComponentConfigurationService::class);
        $s->sync($f['machine']);
        $mc = MachineComponent::where('machine_id', $f['machine']->id)->where('slot_code', 'DRUM_K')->first();
        ComponentLifecycle::create(['machine_component_id' => $mc->id, 'status' => 'closed', 'started_at' => now()->subYear(), 'ended_at' => now()->subMonths(6), 'installed_counter' => 500_000, 'removed_counter' => 900_000, 'active_key' => null]);
        $this->assertSame(0, $mc->lifecycles()->whereIn('status', ['active', 'unknown'])->count());

        $life = $s->initialize($mc, ['started_at' => now(), 'installed_counter' => 1_505_159, 'client_request_id' => (string) Str::uuid()]);

        $this->assertSame('active', $life->status);
        $this->assertSame(2, ComponentLifecycle::where('machine_component_id', $mc->id)->count());
    }

    // E. Inherited assignment: first initialization works exactly like a
    // manual one, and the baseline snapshot resolves from the live profile
    // slot (not a stale machine_component column), per
    // ComponentConfigurationService::resolveEffectiveBaseline().
    public function test_inherited_assignment_first_initialization_snapshots_the_live_slot_baseline(): void
    {
        $f = $this->graph();
        $s = app(ComponentConfigurationService::class);
        $s->sync($f['machine']);
        $mc = MachineComponent::where('machine_id', $f['machine']->id)->where('slot_code', 'DRUM_K')->first();
        $this->assertSame('inherited', $mc->source_type);

        $life = $s->initialize($mc, ['started_at' => now(), 'installed_counter' => 1_505_159, 'client_request_id' => (string) Str::uuid()]);

        $this->assertSame(1_000_000, $life->baseline_expected_clicks_snapshot);
    }

    // F. Tenant / branch boundary: this fix only adds `installed_counter` to
    // the accepted payload for POST .../lifecycles - it must not loosen the
    // existing cross-account authorization check (MachineAccessResolver +
    // EffectiveCapabilityResolver) one inch, including when the payload now
    // also carries a real installed_counter.
    public function test_cross_account_user_is_forbidden_from_initializing_with_an_installed_counter(): void
    {
        $f = $this->graph();
        $s = app(ComponentConfigurationService::class);
        $s->sync($f['machine']);
        $mc = MachineComponent::where('machine_id', $f['machine']->id)->where('slot_code', 'DRUM_K')->first();

        $attacker = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $f['otherAccount']->id, 'user_id' => $attacker->id, 'role' => 'owner', 'status' => 'active', 'accepted_at' => now()]);

        $this->actingAs($attacker)->postJson("/api/v1/machine-components/{$mc->id}/lifecycles", [
            'started_at' => now()->toDateString(),
            'installed_counter' => 1_505_159,
        ])->assertForbidden();

        $this->assertSame(0, ComponentLifecycle::where('machine_component_id', $mc->id)->count());
    }
}
