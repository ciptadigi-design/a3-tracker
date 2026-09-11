<?php

namespace App\Services;

use App\Models\ComponentLifecycle;
use App\Models\ComponentReplacement;
use App\Models\CounterReading;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\MachineComponent;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ReplaceMachineComponent
{
    // M2.17.5: the new lifecycle created here was never given an `installed_counter`
    // baseline (nor was the closed lifecycle given a `removed_counter`/`actual_usage`),
    // so the frontend's health projection (currentUsage = latestCounter -
    // installedCounter) could never compute a real percentage - it silently rendered
    // "0.0%" via Number(null).toFixed(1) instead of the ~100%-remaining a freshly
    // replaced component should show. The fix anchors both the closed lifecycle's exit
    // reading and the new lifecycle's baseline to the SAME "current effective counter at
    // processing time" value (same canonical total_impressions lookup
    // ComponentsController::machineComponents() already uses), which by construction
    // makes usedClicks = 0 / remainingPercent = 100% immediately after this commits,
    // with subsequent real clicks correctly reducing it from there.
    private function currentEffectiveCounter(string $machineId, string $accountId): ?float
    {
        $reading = CounterReading::where('machine_id', $machineId)
            ->where('account_id', $accountId)
            ->where('status', 'effective')
            ->whereHas('counterType', fn ($q) => $q->whereRaw('LOWER(TRIM(code)) = ?', ['total_impressions']))
            ->orderByDesc('observed_at')->orderByDesc('created_at')->orderByDesc('id')
            ->first();

        return $reading === null ? null : (float) $reading->reading_value;
    }

    public function execute(MachineComponent $mc, array $d): ComponentReplacement
    {
        return DB::transaction(function () use ($mc, $d) {
            $mc = MachineComponent::whereKey($mc->id)->lockForUpdate()->firstOrFail();
            if ($mc->status === 'retired' || ! $mc->machine || $mc->machine->status !== 'active') {
                throw new ConflictHttpException('retired or inactive machine component cannot be replaced');
            }
            $old = ComponentReplacement::where('account_id', $mc->account_id)->where('client_request_id', $d['client_request_id'])->first();
            if ($old) {
                return $old;
            }$when = $d['replaced_at'] ?? now();
            $source = $d['inventory_source'] ?? 'external_untracked';
            $movement = null;
            $cost = null;
            if ($source === 'inventory') {
                $item = InventoryItem::findOrFail($d['inventory_item_id']);
                $loc = InventoryLocation::findOrFail($d['inventory_location_id']);
                if ($item->account_id !== $mc->account_id || $loc->account_id !== $mc->account_id) {
                    throw new ConflictHttpException('inventory scope does not match component account');
                }
                if ($item->component_id !== null && $item->component_id !== $mc->component_id) {
                    throw new ConflictHttpException('inventory item component mismatch');
                }$movement = app(InventoryLedgerService::class)->outbound($item, $loc, (float) ($d['quantity'] ?? 1), 'replacement_consumption', $d['client_request_id'], null, $d['notes'] ?? null, null, $d['performed_by_person_id'] ?? null, $d['performed_by_name'] ?? null, $d['entered_by'] ?? null);
                $cost = FifoAllocationCost::forMovement($movement->id);
            } else {
                if (empty($d['external_reason'])) {
                    throw new ConflictHttpException('external reason required');
                }
            }$active = $mc->lifecycles()->whereIn('status', ['active', 'unknown'])->lockForUpdate()->first();
            $previous = null;
            $baselineCounter = $this->currentEffectiveCounter($mc->machine_id, $mc->account_id);
            if ($active) {
                $previous = $active->id;
                $actualUsage = $baselineCounter !== null && $active->installed_counter !== null
                    ? $baselineCounter - (float) $active->installed_counter
                    : null;
                $active->update(['status' => 'closed', 'ended_at' => $when, 'active_key' => null, 'removed_counter' => $baselineCounter, 'actual_usage' => $actualUsage]);
            }$next = ComponentLifecycle::create(['machine_component_id' => $mc->id, 'installed_counter' => $baselineCounter, 'started_at' => $when, 'status' => 'active', 'source' => 'replacement', 'active_key' => 'active', 'notes' => $d['notes'] ?? null]);

            return ComponentReplacement::create(['account_id' => $mc->account_id, 'machine_component_id' => $mc->id, 'inventory_item_id' => $source === 'inventory' ? $item->id : null, 'inventory_location_id' => $source === 'inventory' ? $loc->id : null, 'inventory_movement_id' => $movement?->id, 'previous_lifecycle_id' => $previous, 'new_lifecycle_id' => $next->id, 'inventory_source' => $source, 'quantity' => $source === 'inventory' ? ($d['quantity'] ?? 1) : null, 'consumed_cost' => $cost, 'replaced_at' => $when, 'external_reason' => $source === 'external_untracked' ? $d['external_reason'] : null, 'notes' => $d['notes'] ?? null, 'entered_by' => $d['entered_by'] ?? null, 'performed_by_person_id' => $d['performed_by_person_id'] ?? null, 'performed_by_name_snapshot' => $d['performed_by_name'] ?? null, 'client_request_id' => $d['client_request_id']]);
        }, 3);
    }
}
class FifoAllocationCost
{
    public static function forMovement(string $id): ?float
    {
        $rows = DB::table('fifo_allocations')->where('outbound_movement_id', $id)->get();
        if ($rows->contains(fn ($r) => $r->allocated_cost === null)) {
            return null;
        }

        return (float) $rows->sum('allocated_cost');
    }
}
