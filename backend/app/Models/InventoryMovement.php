<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class InventoryMovement extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'inventory_item_id', 'location_id', 'movement_type', 'quantity', 'occurred_at', 'reference_type', 'reference_id', 'reason', 'client_request_id', 'transfer_id'];

    protected $casts = ['occurred_at' => 'datetime'];

    protected static function booted()
    {
        static::creating(fn ($m) => $m->id ??= Str::uuid());
    }
}
