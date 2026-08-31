<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ComponentReplacement extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'machine_component_id', 'inventory_item_id', 'inventory_location_id', 'inventory_movement_id', 'previous_lifecycle_id', 'new_lifecycle_id', 'inventory_source', 'quantity', 'consumed_cost', 'replaced_at', 'external_reason', 'notes', 'client_request_id'];

    protected $casts = ['replaced_at' => 'datetime'];

    protected static function booted()
    {
        static::creating(fn ($m) => $m->id ??= Str::uuid());
    }
}
