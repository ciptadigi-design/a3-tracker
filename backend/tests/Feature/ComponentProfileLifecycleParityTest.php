<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\ComponentCatalog;
use App\Models\ComponentLifecycle;
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
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ComponentProfileLifecycleParityTest extends TestCase
{
    use RefreshDatabase;

    private function graph(): array
    {
        $a = Account::create(['code' => 'CMP', 'name' => 'Components']);
        $b = Branch::create(['account_id' => $a->id, 'code' => 'MAIN', 'name' => 'Main']);
        $man = Manufacturer::create(['code' => 'KM', 'name' => 'Konica']);
        $c1070 = MachineModel::create(['manufacturer_id' => $man->id, 'model_code' => 'C1070', 'name' => 'C1070']);
        $xerox = MachineModel::create(['manufacturer_id' => $man->id, 'model_code' => 'VERSANT', 'name' => 'Versant']);
        $drum = ComponentCatalog::create(['code' => 'DRUM', 'name' => 'Drum']);
        $profile = ModelProfile::create(['machine_model_id' => $c1070->id, 'name' => 'C1070 profile']);
        foreach (['DRUM-C', 'DRUM-M', 'DRUM-Y', 'DRUM-K'] as $i => $slot) {
            ModelProfileSlot::create(['profile_id' => $profile->id, 'component_id' => $drum->id, 'slot_code' => $slot, 'display_order' => $i]);
        }
        for ($i = 4; $i < 28; $i++) {
            $part = ComponentCatalog::create(['code' => 'P'.$i, 'name' => 'Part '.$i]);
            ModelProfileSlot::create(['profile_id' => $profile->id, 'component_id' => $part->id, 'slot_code' => 'S'.$i, 'display_order' => $i]);
        }
        $aMachine = Machine::create(['account_id' => $a->id, 'branch_id' => $b->id, 'machine_model_id' => $c1070->id, 'machine_code' => 'A', 'display_name' => 'A']);
        $bMachine = Machine::create(['account_id' => $a->id, 'branch_id' => $b->id, 'machine_model_id' => $c1070->id, 'machine_code' => 'B', 'display_name' => 'B']);
        $xMachine = Machine::create(['account_id' => $a->id, 'branch_id' => $b->id, 'machine_model_id' => $xerox->id, 'machine_code' => 'X', 'display_name' => 'X']);

        return compact('a', 'profile', 'drum', 'aMachine', 'bMachine', 'xMachine');
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
        $manual = $s->addManual($f['aMachine'], ['component_id' => $f['drum']->id, 'slot_code' => 'EXTRA-01']);
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
}
