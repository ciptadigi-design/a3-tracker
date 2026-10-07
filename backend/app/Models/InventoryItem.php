<?php

namespace App\Models;

use App\Models\Relations\GlobalOrOwnedBelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class InventoryItem extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'component_id', 'sku', 'name', 'category', 'unit', 'minimum_stock', 'is_active', 'archived_at'];

    protected static function booted()
    {
        static::creating(fn ($m) => $m->id ??= Str::uuid());
    }

    public function component()
    {
        return new GlobalOrOwnedBelongsTo($this->newRelatedInstance(ComponentCatalog::class)->newQuery(), $this, 'component_id', 'component');
    }

    // Phase 1 (additive foundation, feature-dark): the many-to-many compatibility
    // path alongside the existing single `component_id` link above. Nothing yet
    // reads this to change Replace behavior.
    public function compatibilities()
    {
        return $this->hasMany(InventoryComponentCompatibility::class, 'inventory_item_id');
    }
}
