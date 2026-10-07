<?php

namespace App\Services;

use App\Models\ComponentCatalog;
use App\Models\InventoryComponentCompatibility;
use App\Models\InventoryItem;

// Phase 1 (additive foundation): the one place that creates a compatibility
// row, so cross-account scope is validated the same way everywhere a row is
// ever created - by tests now, and by a future admin write endpoint without
// re-deriving this logic. No HTTP route calls this yet (feature-dark).
class InventoryComponentCompatibilityService
{
    // Reuses the same global-or-owned catalog scoping ScopedReference already
    // enforces elsewhere (e.g. ComponentConfigurationService::addManual()) -
    // never a second authorization model. The compatibility row's own
    // account_id is always the inventory item's account_id: inventory_items
    // are never global, so the item is what anchors this mapping to a tenant
    // even when the component side is a global catalog entry.
    public function link(InventoryItem $item, string $componentId): InventoryComponentCompatibility
    {
        ScopedReference::activeGlobalOrOwned(ComponentCatalog::class, $componentId, $item->account_id, 'component_id');

        return InventoryComponentCompatibility::create([
            'account_id' => (string) $item->account_id,
            'inventory_item_id' => (string) $item->id,
            'component_id' => (string) $componentId,
            'is_active' => true,
        ]);
    }
}
