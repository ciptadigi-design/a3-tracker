<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class FifoLayer extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'inventory_item_id', 'location_id', 'inbound_movement_id', 'source_type', 'original_quantity', 'remaining_quantity', 'unit_cost', 'effective_at', 'origin_layer_id', 'fifo_sequence'];

    protected static function booted()
    {
        static::creating(fn ($m) => $m->id ??= Str::uuid());
    }

    public function allocations()
    {
        return $this->hasMany(FifoAllocation::class);
    }
}
