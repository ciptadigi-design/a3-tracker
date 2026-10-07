<?php

namespace App\Services;

use App\Models\ComponentCatalog;
use App\Models\InventoryComponentCompatibility;
use App\Models\InventoryItem;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 transitional data preparation, run once (idempotent/re-runnable) as
 * part of this deploy, before backend enforcement starts requiring an active
 * inventory_component_compatibilities row. Two independent steps:
 *
 * 1. Generic backfill: every active inventory item that already has a
 *    non-null `component_id` gets an explicit compatibility row for that
 *    exact pair - this is a straight transcription of the existing 1:1
 *    relationship into the new many-to-many table, never a guess.
 *
 * 2. Charging Corona mapping: resolves the real generic item and its four
 *    real CMYK component_catalog rows BY NAME, per account, from already
 *    existing, unambiguous data - never a hardcoded/guessed UUID. The four
 *    color-specific inventory items (e.g. "Charging Corona Black") already
 *    carry the authoritative component_id for each color; reading theirs is
 *    the one reliable way to resolve the right catalog row without parsing
 *    component_catalogs.name/code conventions that were never guaranteed.
 *    Fails closed (skips, never guesses) per account on any ambiguity.
 */
class InventoryComponentCompatibilityBackfillService
{
    private const COLOR_WORDS = ['K' => 'black', 'C' => 'cyan', 'M' => 'magenta', 'Y' => 'yellow'];

    public function run(bool $apply): array
    {
        $plan = ['generic_backfill' => [], 'charging_corona' => []];

        DB::beginTransaction();
        try {
            $plan['generic_backfill'] = $this->planGenericBackfill();
            foreach ($plan['generic_backfill'] as $row) {
                InventoryComponentCompatibility::create($row);
            }
            $plan['charging_corona'] = $this->planChargingCoronaMapping();
            foreach ($plan['charging_corona']['created'] as $row) {
                InventoryComponentCompatibility::create($row);
            }

            if ($apply) {
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $plan;
    }

    /** @return array<int,array{account_id:string,inventory_item_id:string,component_id:string,is_active:bool}> */
    private function planGenericBackfill(): array
    {
        $items = InventoryItem::where('is_active', true)->whereNotNull('component_id')->get();
        $plan = [];
        foreach ($items as $item) {
            $exists = InventoryComponentCompatibility::where('inventory_item_id', $item->id)->where('component_id', $item->component_id)->exists();
            if ($exists) {
                continue;
            }
            $plan[] = ['account_id' => (string) $item->account_id, 'inventory_item_id' => (string) $item->id, 'component_id' => (string) $item->component_id, 'is_active' => true];
        }

        return $plan;
    }

    /**
     * @return array{created: array<int,array{account_id:string,inventory_item_id:string,component_id:string,is_active:bool}>, skipped: array<int,array{account_id:string,reason:string}>}
     */
    private function planChargingCoronaMapping(): array
    {
        $created = [];
        $skipped = [];
        $accountIds = InventoryItem::whereRaw('LOWER(TRIM(name)) = ?', ['charging corona'])->whereNull('component_id')->where('is_active', true)->pluck('account_id')->unique();

        foreach ($accountIds as $accountId) {
            $generics = InventoryItem::where('account_id', $accountId)->where('is_active', true)->where('component_id', null)
                ->whereRaw('LOWER(TRIM(name)) = ?', ['charging corona'])->get();
            if ($generics->count() !== 1) {
                $skipped[] = ['account_id' => (string) $accountId, 'reason' => "generic Charging Corona item is ambiguous or not found ({$generics->count()} candidates)"];

                continue;
            }
            $generic = $generics->first();

            $componentIds = [];
            $ambiguous = null;
            foreach (self::COLOR_WORDS as $letter => $colorWord) {
                $candidates = InventoryItem::where('account_id', $accountId)->whereNotNull('component_id')
                    ->whereRaw('LOWER(TRIM(name)) = ?', ['charging corona '.$colorWord])
                    ->get();
                if ($candidates->count() !== 1) {
                    $ambiguous = "Charging Corona {$colorWord} ({$letter}) component definition is ambiguous or not found ({$candidates->count()} candidates)";

                    break;
                }
                $component = ComponentCatalog::whereKey($candidates->first()->component_id)->where('is_active', true)
                    ->where(fn ($q) => $q->whereNull('account_id')->orWhere('account_id', $accountId))->first();
                if (! $component) {
                    $ambiguous = "Charging Corona {$colorWord} ({$letter}) component definition is not an active catalog entry visible to this account";

                    break;
                }
                $componentIds[$letter] = (string) $component->id;
            }
            if ($ambiguous !== null) {
                $skipped[] = ['account_id' => (string) $accountId, 'reason' => $ambiguous];

                continue;
            }
            if (count(array_unique($componentIds)) !== 4) {
                $skipped[] = ['account_id' => (string) $accountId, 'reason' => 'the four resolved CMYK component definitions are not four distinct catalog rows'];

                continue;
            }

            foreach ($componentIds as $componentId) {
                if (InventoryComponentCompatibility::where('inventory_item_id', $generic->id)->where('component_id', $componentId)->exists()) {
                    continue;
                }
                $created[] = ['account_id' => (string) $accountId, 'inventory_item_id' => (string) $generic->id, 'component_id' => $componentId, 'is_active' => true];
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }
}
