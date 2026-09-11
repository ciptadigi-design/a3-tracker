<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Builds frontend-ready Inventory Movement view models from raw
 * `inventory_movements` rows.
 *
 * M2.17.5: InventoryController@workspace returned raw `inventory_movements`
 * rows for every movement type - the Movements tab
 * (src/pages/InventoryPage.jsx's MovementPanel) expects `movement_id`, `sku`,
 * `item_name`, `unit_snapshot`, and `location_name`, none of which exist on
 * the raw row (only real item_id/location_id foreign keys do), so every
 * movement rendered with a blank item name and a blank Location regardless of
 * movement type. `operational_person_name_snapshot` IS a real column and
 * already correctly populated for adjust/transfer/opening/replacement - it
 * only ever reads as missing for Goods Receipt movements, which is the
 * separate PIC-persistence gap fixed in PurchaseReceiptService::receive().
 */
class MovementSummaryBuilder
{
    /**
     * @param  Collection  $movements  rows from `inventory_movements`
     * @param  Collection  $items  Eloquent/stdClass rows with id, name, sku, unit
     * @param  Collection  $locationLookup  Eloquent/stdClass rows with id, name (unfiltered by is_active, so an archived location still resolves)
     * @param  Collection  $userLookup  Eloquent/stdClass rows with id, name (for entered_by -> "Entered By" in the detail modal)
     */
    public function build(Collection $movements, Collection $items, Collection $locationLookup, Collection $userLookup): Collection
    {
        $itemsById = $items->keyBy('id');
        $locationsById = $locationLookup->keyBy('id');
        $usersById = $userLookup->keyBy('id');

        return $movements->map(function ($movement) use ($itemsById, $locationsById, $usersById) {
            $item = $itemsById->get($movement->inventory_item_id);
            $location = $locationsById->get($movement->location_id);
            $enteredByUser = $movement->entered_by ? $usersById->get($movement->entered_by) : null;

            return (object) array_merge((array) $movement, [
                'movement_id' => $movement->id,
                'item_name' => $item->name ?? 'Unknown item',
                'sku' => $item->sku ?? null,
                'unit_snapshot' => $item->unit ?? 'pcs',
                'location_name' => $location->name ?? 'Unknown location',
                'created_by_name_snapshot' => $enteredByUser->name ?? null,
            ]);
        })->values();
    }
}
