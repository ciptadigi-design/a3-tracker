<?php

namespace App\Services;

use App\Models\ComponentCatalog;
use App\Models\InventoryComponentCompatibility;
use App\Models\InventoryItem;
use Illuminate\Support\Facades\DB;

/**
 * Transitional data preparation, run once (idempotent/re-runnable) as part of
 * a deploy, before backend enforcement starts requiring an active
 * inventory_component_compatibilities row. Two independent steps:
 *
 * 1. Generic backfill: every active inventory item that already has a
 *    non-null `component_id` gets an explicit compatibility row for that
 *    exact pair - this is a straight transcription of the existing 1:1
 *    relationship into the new many-to-many table, never a guess.
 *
 * 2. Generic color-family mapping: resolves a generic physical item (any
 *    active item with component_id NULL - "Charging Corona", "Drum Unit",
 *    "Developing Unit", whatever real data contains) to the component
 *    catalog rows its four real CMYK siblings already carry, BY NAME, per
 *    account - never a hardcoded family name, never a guessed/hardcoded
 *    catalog id. A sibling is an active, non-null-component_id item in the
 *    same account named exactly "<generic name> <color word>"; its
 *    component_id is the authoritative catalog row for that color (reading
 *    it is the one reliable way to resolve the right catalog row without
 *    parsing component_catalogs.name/code conventions that were never
 *    guaranteed). Fails closed (skips, never guesses) per candidate on any
 *    ambiguity - including a generic item with no four-color sibling set at
 *    all (e.g. a true miscellaneous "Other Part" bucket).
 */
class InventoryComponentCompatibilityBackfillService
{
    private const COLOR_WORDS = ['K' => 'black', 'C' => 'cyan', 'M' => 'magenta', 'Y' => 'yellow'];

    public function run(bool $apply): array
    {
        $plan = ['generic_backfill' => [], 'generic_color_families' => []];

        DB::beginTransaction();
        try {
            $plan['generic_backfill'] = $this->planGenericBackfill();
            foreach ($plan['generic_backfill'] as $row) {
                InventoryComponentCompatibility::create($row);
            }
            $plan['generic_color_families'] = $this->planGenericColorFamilyMappings();
            foreach ($plan['generic_color_families']['created'] as $row) {
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
     * Data-driven, family-name-agnostic: every active item with a NULL
     * component_id is a candidate generic physical part. No family name
     * (e.g. "Charging Corona", "Drum Unit") is ever hardcoded here - the
     * candidate's own name, read from existing data, is what's used to look
     * up its four color siblings.
     *
     * @return array{created: array<int,array{account_id:string,inventory_item_id:string,component_id:string,is_active:bool}>, skipped: array<int,array{account_id:string,inventory_item_id:string,name:string,reason:string}>}
     */
    private function planGenericColorFamilyMappings(): array
    {
        $created = [];
        $skipped = [];
        $candidates = InventoryItem::where('is_active', true)->whereNull('component_id')->get();
        $byAccountAndName = $candidates->groupBy(fn ($item) => $item->account_id.'::'.strtolower(trim($item->name)));

        foreach ($byAccountAndName as $group) {
            if ($group->count() > 1) {
                foreach ($group as $duplicate) {
                    $skipped[] = ['account_id' => (string) $duplicate->account_id, 'inventory_item_id' => (string) $duplicate->id, 'name' => trim($duplicate->name), 'reason' => "generic item name is ambiguous - {$group->count()} active items share this exact name in this account"];
                }

                continue;
            }
            $generic = $group->first();
            $baseName = trim($generic->name);
            $componentIds = [];
            $ambiguous = null;
            foreach (self::COLOR_WORDS as $letter => $colorWord) {
                $siblings = InventoryItem::where('account_id', $generic->account_id)->whereNotNull('component_id')
                    ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($baseName.' '.$colorWord)])
                    ->get();
                if ($siblings->count() !== 1) {
                    $ambiguous = "{$baseName} {$colorWord} ({$letter}) sibling item is ambiguous or not found ({$siblings->count()} candidates)";

                    break;
                }
                $component = ComponentCatalog::whereKey($siblings->first()->component_id)->where('is_active', true)
                    ->where(fn ($q) => $q->whereNull('account_id')->orWhere('account_id', $generic->account_id))->first();
                if (! $component) {
                    $ambiguous = "{$baseName} {$colorWord} ({$letter}) component definition is not an active catalog entry visible to this account";

                    break;
                }
                $componentIds[$letter] = (string) $component->id;
            }
            if ($ambiguous !== null) {
                $skipped[] = ['account_id' => (string) $generic->account_id, 'inventory_item_id' => (string) $generic->id, 'name' => $baseName, 'reason' => $ambiguous];

                continue;
            }
            if (count(array_unique($componentIds)) !== 4) {
                $skipped[] = ['account_id' => (string) $generic->account_id, 'inventory_item_id' => (string) $generic->id, 'name' => $baseName, 'reason' => 'the four resolved CMYK component definitions are not four distinct catalog rows'];

                continue;
            }

            foreach ($componentIds as $componentId) {
                if (InventoryComponentCompatibility::where('inventory_item_id', $generic->id)->where('component_id', $componentId)->exists()) {
                    continue;
                }
                $created[] = ['account_id' => (string) $generic->account_id, 'inventory_item_id' => (string) $generic->id, 'component_id' => $componentId, 'is_active' => true];
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }
}
