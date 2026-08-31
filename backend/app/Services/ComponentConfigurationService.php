<?php

namespace App\Services;

use App\Models\ComponentCatalog;
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
        if ($machine->status !== null && $machine->status !== 'active') {
            return 0;
        }

        return DB::transaction(function () use ($machine) {
            $machine->lockForUpdate()->first();
            MachineComponent::where('machine_id', $machine->id)->where('source_type', 'inherited')->where('status', 'configured')
                ->whereDoesntHave('lifecycles')->where(function ($q) {
                    $q->whereDoesntHave('profileSlot')->orWhereHas('profileSlot.profile', fn ($p) => $p->where('is_active', false));
                })->update(['status' => 'retired', 'active_key' => null]);
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
        if ($machine->status !== null && $machine->status !== 'active') {
            throw new ConflictHttpException('machine is not active');
        }

        if (($d['tracking_method'] ?? 'counter_based') !== 'counter_based') {
            throw new ConflictHttpException('only counter-based lifecycle tracking is currently supported');
        }
        if (empty($d['baseline_expected_clicks']) || (int) $d['baseline_expected_clicks'] < 1) {
            throw new ConflictHttpException('expected baseline is required for machine-specific lifecycle tracking');
        }

        return DB::transaction(function () use ($machine, $d) {
            $slotCode = strtoupper(trim((string) $d['slot_code']));
            $component = ComponentCatalog::where('id', $d['component_id'])
                ->where(fn ($q) => $q->whereNull('account_id')->orWhere('account_id', $machine->account_id))
                ->first();
            if (! $component || ! $component->is_active) {
                throw new ConflictHttpException('[COMPONENT_NOT_FOUND] Select an active Component Catalog entry for this account.');
            }

            $standard = ModelProfileSlot::where('is_active', true)
                ->whereRaw('UPPER(TRIM(slot_code)) = ?', [$slotCode])
                ->whereHas('profile', fn ($q) => $q->where('machine_model_id', $machine->machine_model_id)
                    ->where('is_active', true)
                    ->where(fn ($scope) => $scope->whereNull('account_id')->orWhere('account_id', $machine->account_id)))
                ->with('profile.machineModel')->first();
            if ($standard) {
                $modelName = $standard->profile?->machineModel?->name ?? 'this machine model';
                if (MachineComponentExclusion::where('machine_id', $machine->id)->where('profile_slot_id', $standard->id)->whereNull('cleared_at')->exists()) {
                    throw new ConflictHttpException("[PROFILE_SLOT_EXCLUDED] This standard slot is currently excluded from {$modelName}. Restore the Model Profile assignment if the component should be active.");
                }
                throw new ConflictHttpException("[STANDARD_PROFILE_SLOT] This slot is already defined as a standard component for {$modelName}. Use Sync Model Profile instead.");
            }
            if (MachineComponent::where('machine_id', $machine->id)->where('status', 'configured')->whereRaw('UPPER(TRIM(slot_code)) = ?', [$slotCode])->exists()) {
                throw new ConflictHttpException("[EXISTING_MACHINE_ASSIGNMENT] This machine already has a component assigned to slot {$slotCode}.");
            }

            return MachineComponent::create(['account_id' => $machine->account_id, 'machine_id' => $machine->id, 'component_id' => $component->id, 'slot_code' => $slotCode, 'source_type' => 'manual', 'status' => 'configured', 'active_key' => 'active', 'display_order' => $d['display_order'] ?? 0, 'tracking_method' => $d['tracking_method'], 'baseline_expected_clicks' => $d['baseline_expected_clicks'], 'notes' => $d['notes'] ?? null]);
        });
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
            if ($mc->source_type === 'manual' && ($mc->tracking_method !== 'counter_based' || ! $mc->baseline_expected_clicks)) {
                throw new ConflictHttpException('machine-specific component needs a counter-based expected baseline before initialization');
            }
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

    public function reconcileManual(MachineComponent $mc, ModelProfileSlot $slot): MachineComponent
    {
        return DB::transaction(function () use ($mc, $slot) {
            $mc->lockForUpdate()->first();
            $slot->loadMissing('profile');
            $machine = $mc->machine()->first();
            $profile = $slot->profile;
            if (! $machine || $machine->account_id !== $mc->account_id || ($machine->status !== null && $machine->status !== 'active') || $profile === null
                || ($profile->account_id !== null && $profile->account_id !== $mc->account_id)
                || $mc->source_type !== 'manual' || ! (bool) $slot->is_active
                || ! (bool) $profile->is_active || (string) $profile->machine_model_id !== (string) $machine->machine_model_id
                || (string) $slot->component_id !== (string) $mc->component_id
                || strtolower(trim($slot->slot_code)) !== strtolower(trim($mc->slot_code))) {
                throw new ConflictHttpException('manual component and active profile slot do not match');
            }
            if (MachineComponentExclusion::where('machine_id', $mc->machine_id)->where('profile_slot_id', $slot->id)->whereNull('cleared_at')->exists()) {
                throw new ConflictHttpException('active profile exclusion blocks reconciliation');
            }
            if (MachineComponent::where('machine_id', $mc->machine_id)->where('status', 'configured')->where('id', '<>', $mc->id)->where(function ($q) use ($slot) {
                $q->where('profile_slot_id', $slot->id)->orWhereRaw('LOWER(TRIM(slot_code)) = ?', [strtolower(trim($slot->slot_code))]);
            })->exists()) {
                throw new ConflictHttpException('a conflicting inherited assignment already exists');
            }
            $mc->update(['profile_slot_id' => $slot->id, 'source_type' => 'inherited', 'tracking_method' => $slot->tracking_method, 'baseline_expected_clicks' => $slot->baseline_expected_clicks]);

            return $mc->fresh();
        });
    }

    public function reconciliationCandidate(MachineComponent $mc): array
    {
        $mc->loadMissing(['machine', 'machine.model', 'component']);
        $slot = ModelProfileSlot::with('profile')->where('is_active', true)
            ->whereHas('profile', fn ($q) => $q->where('is_active', true)
                ->where('machine_model_id', $mc->machine?->machine_model_id)
                ->where(fn ($x) => $x->whereNull('account_id')->orWhere('account_id', $mc->account_id)))
            ->where('component_id', $mc->component_id)
            ->whereRaw('LOWER(TRIM(slot_code)) = ?', [strtolower(trim((string) $mc->slot_code))])
            ->get()->first();
        $eligible = $mc->source_type === 'manual' && $mc->status === 'configured' && $slot !== null
            && ! MachineComponentExclusion::where('machine_id', $mc->machine_id)->where('profile_slot_id', $slot?->id)->whereNull('cleared_at')->exists()
            && ! MachineComponent::where('machine_id', $mc->machine_id)->where('status', 'configured')->where('id', '<>', $mc->id)->where(function ($q) use ($slot) {
                $q->where('profile_slot_id', $slot?->id)->orWhereRaw('LOWER(TRIM(slot_code)) = ?', [strtolower(trim((string) $mc->slot_code))]);
            })->exists();

        return ['eligible' => $eligible, 'reason' => $eligible ? null : 'No deterministic compatible profile slot is available.', 'machine_component_id' => $mc->id,
            'current_slot_code' => $mc->slot_code, 'profile_slot_id' => $slot?->id, 'profile_slot_code' => $slot?->slot_code,
            'machine_model' => $mc->machine?->model?->name, 'component' => $mc->component?->name, 'preserves_identity' => true];
    }
}
