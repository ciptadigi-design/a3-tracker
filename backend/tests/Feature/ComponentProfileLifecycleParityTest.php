<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\ComponentCatalog;
use App\Models\ComponentLifecycle;
use App\Models\ComponentReplacement;
use App\Models\Machine;
use App\Models\MachineComponent;
use App\Models\MachineComponentExclusion;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\ModelProfile;
use App\Models\ModelProfileSlot;
use App\Services\ComponentConfigurationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ComponentProfileLifecycleParityTest extends TestCase
{
    use RefreshDatabase;

    private function graph(bool $withProfile = true): array
    {
        $a = Account::create(['code' => 'CMP', 'name' => 'Components']);
        $b = Branch::create(['account_id' => $a->id, 'code' => 'MAIN', 'name' => 'Main']);
        $man = Manufacturer::create(['code' => 'KM', 'name' => 'Konica']);
        $c1070 = MachineModel::create(['manufacturer_id' => $man->id, 'model_code' => 'C1070', 'name' => 'C1070']);
        $xerox = MachineModel::create(['manufacturer_id' => $man->id, 'model_code' => 'VERSANT', 'name' => 'Versant']);
        $drum = ComponentCatalog::create(['code' => 'DRUM', 'name' => 'Drum']);
        $profile = null;
        if ($withProfile) {
            $profile = ModelProfile::create(['machine_model_id' => $c1070->id, 'name' => 'C1070 profile']);
            foreach (['DRUM-C', 'DRUM-M', 'DRUM-Y', 'DRUM-K'] as $i => $slot) {
                ModelProfileSlot::create(['profile_id' => $profile->id, 'component_id' => $drum->id, 'slot_code' => $slot, 'display_order' => $i]);
            }
            for ($i = 4; $i < 28; $i++) {
                $part = ComponentCatalog::create(['code' => 'P'.$i, 'name' => 'Part '.$i]);
                ModelProfileSlot::create(['profile_id' => $profile->id, 'component_id' => $part->id, 'slot_code' => 'S'.$i, 'display_order' => $i]);
            }
        }
        $aMachine = Machine::create(['account_id' => $a->id, 'branch_id' => $b->id, 'machine_model_id' => $c1070->id, 'machine_code' => 'A', 'display_name' => 'A']);
        $bMachine = Machine::create(['account_id' => $a->id, 'branch_id' => $b->id, 'machine_model_id' => $c1070->id, 'machine_code' => 'B', 'display_name' => 'B']);
        $xMachine = Machine::create(['account_id' => $a->id, 'branch_id' => $b->id, 'machine_model_id' => $xerox->id, 'machine_code' => 'X', 'display_name' => 'X']);

        return compact('a', 'profile', 'drum', 'aMachine', 'bMachine', 'xMachine');
    }

    private function createProfileSlots(array &$f, array $slots = ['DRUM-C']): void
    {
        $f['profile'] = ModelProfile::create(['machine_model_id' => $f['aMachine']->machine_model_id, 'name' => 'C1070 profile']);
        foreach ($slots as $i => $slot) {
            ModelProfileSlot::create(['profile_id' => $f['profile']->id, 'component_id' => $f['drum']->id, 'slot_code' => $slot, 'display_order' => $i]);
        }
    }

    public function test_model_specific_repeated_slots_and_idempotent_sync(): void
    {
        $f = $this->graph();
        $s = app(ComponentConfigurationService::class);
        $this->assertSame(28, $s->sync($f['aMachine']));
        $this->assertSame(0, $s->sync($f['aMachine']));
        $this->assertSame(28, MachineComponent::where('machine_id', $f['aMachine']->id)->count());
        $this->assertSame(4, MachineComponent::where('machine_id', $f['aMachine']->id)->where('component_id', $f['drum']->id)->count());
        $this->assertSame(0, ComponentLifecycle::count());
        $this->assertSame(0, $s->sync($f['xMachine']));
    }

    public function test_slot_identity_manual_add_and_exclusion_are_machine_specific(): void
    {
        $f = $this->graph();
        $s = app(ComponentConfigurationService::class);
        $s->sync($f['aMachine']);
        $s->sync($f['bMachine']);
        $manual = $s->addManual($f['aMachine'], ['component_id' => $f['drum']->id, 'slot_code' => 'EXTRA-01', 'tracking_method' => 'counter_based', 'baseline_expected_clicks' => 100]);
        $this->assertTrue($manual->source_type === 'manual');
        $this->assertFalse(MachineComponent::where('machine_id', $f['bMachine']->id)->where('slot_code', 'EXTRA-01')->exists());
        $target = MachineComponent::where('machine_id', $f['aMachine']->id)->where('slot_code', 'DRUM-C')->first();
        $s->exclude($target, 'not fitted');
        $this->assertTrue(MachineComponentExclusion::where('machine_id', $f['aMachine']->id)->where('profile_slot_id', $target->profile_slot_id)->exists());
        $s->sync($f['aMachine']);
        $this->assertFalse(MachineComponent::where('machine_id', $f['aMachine']->id)->where('slot_code', 'DRUM-C')->where('status', 'configured')->exists());
    }

    public function test_historical_component_cannot_be_excluded_and_lifecycle_is_idempotent(): void
    {
        $f = $this->graph();
        $s = app(ComponentConfigurationService::class);
        $s->sync($f['aMachine']);
        $mc = MachineComponent::where('machine_id', $f['aMachine']->id)->first();
        $id = (string) Str::uuid();
        $life = $s->initialize($mc, ['started_at' => now()->subDay(), 'client_request_id' => $id]);
        $this->assertSame((string) $life->id, (string) $s->initialize($mc, ['started_at' => now()->subDay(), 'client_request_id' => $id])->id);
        $this->expectException(ConflictHttpException::class);
        $s->exclude($mc, 'historic');
    }

    public function test_clear_exclusion_restores_unknown_and_profile_archive_preserves_history(): void
    {
        $f = $this->graph();
        $s = app(ComponentConfigurationService::class);
        $s->sync($f['aMachine']);
        $mc = MachineComponent::where('machine_id', $f['aMachine']->id)->where('slot_code', 'DRUM-C')->first();
        $s->exclude($mc, 'optional');
        $e = MachineComponentExclusion::where('machine_id', $f['aMachine']->id)->where('profile_slot_id', $mc->profile_slot_id)->first();
        $s->clearExclusion($e);
        $s->sync($f['aMachine']);
        $this->assertSame('configured', $mc->fresh()->status);
        $this->assertCount(0, $mc->fresh()->lifecycles);
        $f['profile']->update(['is_active' => false]);
        $s->sync($f['aMachine']);
        $this->assertSame('retired', $mc->fresh()->status);
    }

    public function test_duplicate_slot_code_is_rejected_but_same_component_other_slot_is_valid(): void
    {
        $f = $this->graph();
        $slot = ModelProfileSlot::where('profile_id', $f['profile']->id)->first();
        $this->expectException(QueryException::class);
        ModelProfileSlot::create(['profile_id' => $f['profile']->id, 'component_id' => $slot->component_id, 'slot_code' => $slot->slot_code]);
    }

    public function test_configured_manual_component_initializes_without_profile_or_stock(): void
    {
        $f = $this->graph();
        $service = app(ComponentConfigurationService::class);
        $manual = $service->addManual($f['aMachine'], [
            'component_id' => $f['drum']->id,
            'slot_code' => 'LOCAL-DRUM',
            'tracking_method' => 'counter_based',
            'baseline_expected_clicks' => 1200,
        ]);

        $life = $service->initialize($manual, ['started_at' => now(), 'client_request_id' => (string) Str::uuid()]);

        $this->assertNull($manual->profile_slot_id);
        $this->assertSame($manual->id, $life->machine_component_id);
        $this->assertSame(0, DB::table('inventory_movements')->count());
    }

    public function test_second_active_lifecycle_and_invalid_chronology_are_rejected(): void
    {
        $f = $this->graph();
        $s = app(ComponentConfigurationService::class);
        $s->sync($f['aMachine']);
        $mc = MachineComponent::where('machine_id', $f['aMachine']->id)->first();
        $s->initialize($mc, ['started_at' => '2026-01-01 00:00:00', 'client_request_id' => (string) Str::uuid()]);
        try {
            $s->initialize($mc, ['started_at' => '2026-01-02 00:00:00', 'client_request_id' => (string) Str::uuid()]);
            $this->fail('second active lifecycle should fail');
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }
        $this->assertSame(1, ComponentLifecycle::where('machine_component_id', $mc->id)->count());
    }

    public function test_manual_reconciliation_preserves_identity_and_lifecycle_history(): void
    {
        $f = $this->graph(false);
        $service = app(ComponentConfigurationService::class);
        $manual = $service->addManual($f['aMachine'], ['component_id' => $f['drum']->id, 'slot_code' => 'DRUM-C', 'tracking_method' => 'counter_based', 'baseline_expected_clicks' => 100]);
        $this->createProfileSlots($f);
        $slot = ModelProfileSlot::where('slot_code', 'DRUM-C')->first();
        $life = $service->initialize($manual, ['started_at' => '2026-01-01 00:00:00', 'evidence_level' => 'A', 'source' => 'manual', 'notes' => 'observed', 'client_request_id' => (string) Str::uuid()]);
        $before = $life->fresh()->toArray();
        $id = $manual->id;
        $result = $service->reconcileManual($manual, $slot);
        $this->assertSame((string) $id, (string) $result->id);
        $this->assertSame((string) $slot->id, (string) $result->profile_slot_id);
        $this->assertSame('inherited', $result->source_type);
        $after = $life->fresh();
        $this->assertSame((string) $life->id, (string) $after->id);
        $this->assertSame($before['started_at'], $after->started_at?->toJSON());
        $this->assertSame($before['evidence_level'], $after->evidence_level);
        $this->assertSame(1, ComponentLifecycle::where('machine_component_id', $id)->count());
    }

    public function test_reconciliation_rejects_wrong_slot_model_component_exclusion_and_conflict(): void
    {
        $f = $this->graph(false);
        $service = app(ComponentConfigurationService::class);
        $manual = $service->addManual($f['aMachine'], ['component_id' => $f['drum']->id, 'slot_code' => 'DRUM-C', 'tracking_method' => 'counter_based', 'baseline_expected_clicks' => 100]);
        $other = $service->addManual($f['bMachine'], ['component_id' => $f['drum']->id, 'slot_code' => 'DRUM-C', 'tracking_method' => 'counter_based', 'baseline_expected_clicks' => 100]);
        $this->createProfileSlots($f, ['DRUM-C', 'DRUM-M', 'DRUM-Y', 'DRUM-K']);
        $slots = ModelProfileSlot::where('profile_id', $f['profile']->id)->get()->keyBy('slot_code');
        foreach (['DRUM-M', 'DRUM-Y', 'DRUM-K'] as $wrong) {
            try {
                $service->reconcileManual($manual, $slots[$wrong]);
                $this->fail('wrong slot accepted');
            } catch (ConflictHttpException) {
                $this->assertTrue(true);
            }
        }
        MachineComponentExclusion::create(['account_id' => $f['a']->id, 'machine_id' => $f['aMachine']->id, 'profile_slot_id' => $slots['DRUM-C']->id, 'slot_code' => 'DRUM-C', 'reason' => 'review']);
        try {
            $service->reconcileManual($manual, $slots['DRUM-C']);
            $this->fail('excluded assignment accepted');
        } catch (ConflictHttpException) {
            $this->assertTrue(true);
        }
        $this->assertNotSame($manual->id, $other->id);
    }

    public function test_ordinary_sync_does_not_reconcile_manual_assignment(): void
    {
        $f = $this->graph(false);
        $service = app(ComponentConfigurationService::class);
        $manual = $service->addManual($f['aMachine'], ['component_id' => $f['drum']->id, 'slot_code' => 'DRUM-C', 'tracking_method' => 'counter_based', 'baseline_expected_clicks' => 100]);
        $this->createProfileSlots($f);
        $id = (string) $manual->id;
        $service->sync($f['aMachine']);
        $fresh = $manual->fresh();
        $this->assertSame($id, (string) $fresh->id);
        $this->assertSame('manual', $fresh->source_type);
        $this->assertNull($fresh->profile_slot_id);
        $this->assertSame(1, MachineComponent::where('machine_id', $f['aMachine']->id)->where('slot_code', 'DRUM-C')->count());
    }

    public function test_reconciliation_preserves_replacement_history_join_and_cost_evidence(): void
    {
        $f = $this->graph(false);
        $service = app(ComponentConfigurationService::class);
        $manual = $service->addManual($f['aMachine'], ['component_id' => $f['drum']->id, 'slot_code' => 'DRUM-C', 'tracking_method' => 'counter_based', 'baseline_expected_clicks' => 100]);
        $this->createProfileSlots($f);
        $slot = ModelProfileSlot::where('slot_code', 'DRUM-C')->first();
        $life = $service->initialize($manual, ['client_request_id' => (string) Str::uuid()]);
        $replacementLifecycle = ComponentLifecycle::create(['machine_component_id' => $manual->id, 'status' => 'unknown']);
        $replacement = ComponentReplacement::create(['account_id' => $f['a']->id, 'machine_component_id' => $manual->id, 'new_lifecycle_id' => $replacementLifecycle->id, 'inventory_source' => 'external_untracked', 'quantity' => 1, 'consumed_cost' => 123.45, 'replaced_at' => now(), 'external_reason' => 'historical evidence', 'client_request_id' => (string) Str::uuid()]);
        $service->reconcileManual($manual, $slot);
        $this->assertSame((string) $manual->id, (string) $replacement->fresh()->machine_component_id);
        $this->assertSame('123.45', (string) $replacement->fresh()->consumed_cost);
        $this->assertSame((string) $life->id, (string) $life->fresh()->id);
    }
}
