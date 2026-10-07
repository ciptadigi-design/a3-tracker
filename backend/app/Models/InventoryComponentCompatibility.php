<?php

namespace App\Models;

use App\Models\Relations\GlobalOrOwnedBelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

// Phase 1 (additive foundation): declares that an inventory_items row is a
// valid physical substitute for a component_catalogs type - e.g. one generic
// "Charging Corona" inventory item compatible with all four CMYK component
// catalog rows. component_catalogs is the LOCKED compatibility target, never
// machine_components/component_lifecycles/model_profile_slots (those are
// per-machine/per-install instances, not the stable catalog definition).
//
// Feature-dark in Phase 1: nothing yet reads this table to change Replace
// behavior. inventory_items.component_id (the existing single nullable FK)
// is untouched and remains authoritative until a later phase.
class InventoryComponentCompatibility extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'inventory_item_id', 'component_id', 'is_active', 'archived_at'];

    protected $casts = ['is_active' => 'boolean', 'archived_at' => 'datetime'];

    protected static function booted()
    {
        static::creating(fn ($m) => $m->id ??= Str::uuid());
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function component()
    {
        return new GlobalOrOwnedBelongsTo($this->newRelatedInstance(ComponentCatalog::class)->newQuery(), $this, 'component_id', 'component');
    }
}
