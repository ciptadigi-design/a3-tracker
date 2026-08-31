<?php

namespace App\Services;

use App\Models\ComponentLifecycle;
use App\Models\Machine;
use App\Models\MachineComponent;
use App\Models\MachineComponentExclusion;
use App\Models\ModelProfileSlot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ComponentConfigurationService
{
    public function sync(Machine $machine): int
    {
        if ($machine->status !== 'active') {
            return 0;
        }

        return DB::transaction(function () use ($machine) {
            $machine->lockForUpdate()->first();
            $slots = ModelProfileSlot::whereHas('profile', fn ($q) => $q->where('machine_model_id', $machine->machine_model_id)->where('is_active', true)->where(fn ($x) => $x->whereNull('account_id')->orWhere('account_id', $machine->account_id)))->where('is_active', true)->with('profile')->orderBy('display_order')->orderBy('slot_code')->get();
            $n = 0;
            foreach ($slots as $s) {
                if (MachineComponentExclusion::where('machine_id', $machine->id)->where('profile_slot_id', $s->id)->whereNull('cleared_at')->exists()) {
                    continue;
                } $existing = MachineComponent::where('machine_id', $machine->id)->where('profile_slot_id', $s->id)->first();
                if ($existing) {
                    if ($existing->status === 'retired' && ! $existing->lifecycles()->exists()) {
                        $existing->update(['status' => 'configured', 'active_key' => 'active']);
                        $n++;
                    }

                    continue;
                } if (MachineComponent::where('machine_id', $machine->id)->where('slot_code', $s->slot_code)->where('status', 'configured')->exists()) {
                    continue;
                } MachineComponent::create(['account_id' => $machine->account_id, 'machine_id' => $machine->id, 'component_id' => $s->component_id, 'profile_slot_id' => $s->id, 'slot_code' => $s->slot_code, 'source_type' => 'inherited', 'status' => 'configured', 'active_key' => 'active', 'display_order' => $s->display_order]);
                $n++;
            }

            return $n;
        });
    }

    public function addManual(Machine $machine, array $d): MachineComponent
    {
        if ($machine->status !== 'active') {
            throw new ConflictHttpException('machine is not active');
        }

        return DB::transaction(fn () => MachineComponent::create(['account_id' => $machine->account_id, 'machine_id' => $machine->id, 'component_id' => $d['component_id'], 'slot_code' => strtoupper(trim($d['slot_code'])), 'source_type' => 'manual', 'status' => 'configured', 'active_key' => 'active', 'display_order' => $d['display_order'] ?? 0]));
    }

    public function exclude(MachineComponent $mc, string $reason, ?string $requestId = null): void
    {
        DB::transaction(function () use ($mc, $reason, $requestId) {
            $mc->lockForUpdate()->first();
            if ($mc->source_type !== 'inherited' || $mc->lifecycles()->exists()) {
                throw new ConflictHttpException('historical component cannot be excluded');
            } MachineComponentExclusion::firstOrCreate(['machine_id' => $mc->machine_id, 'profile_slot_id' => $mc->profile_slot_id, 'cleared_at' => null], ['id' => Str::uuid(), 'account_id' => $mc->account_id, 'slot_code' => $mc->slot_code, 'reason' => $reason, 'client_request_id' => $requestId]);
            $mc->update(['status' => 'retired', 'active_key' => null]);
        });
    }

    public function clearExclusion(MachineComponentExclusion $e, ?string $user = null): void
    {
        $e->update(['cleared_at' => now(), 'cleared_by' => $user]);
    }

    public function initialize(MachineComponent $mc, array $d): ComponentLifecycle
    {
        return DB::transaction(function () use ($mc, $d) {
            $mc->lockForUpdate()->first();
            if ($d['client_request_id'] ?? null) {
                $old = ComponentLifecycle::where('client_request_id', $d['client_request_id'])->first();
                if ($old) {
                    return $old;
                }
            } if ($mc->lifecycles()->whereIn('status', ['active', 'unknown'])->exists()) {
                throw new ConflictHttpException('active lifecycle already exists');
            } $start = $d['started_at'] ?? null;
            if (isset($d['ended_at']) && $start && $d['ended_at'] <= $start) {
                throw new ConflictHttpException('invalid lifecycle chronology');
            }
            if ($start && $mc->lifecycles()->whereNotNull('started_at')->where(function ($q) use ($start) {
                $q->whereNull('ended_at')->orWhere('ended_at', '>', $start);
            })->exists()) {
                throw new ConflictHttpException('lifecycle interval overlaps existing history');
            }

            return ComponentLifecycle::create(['machine_component_id' => $mc->id, 'started_at' => $start, 'status' => $start ? 'active' : 'unknown', 'evidence_level' => $d['evidence_level'] ?? null, 'source' => $d['source'] ?? 'manual', 'notes' => $d['notes'] ?? null, 'client_request_id' => $d['client_request_id'] ?? null, 'active_key' => 'active']);
        });
    }
}
