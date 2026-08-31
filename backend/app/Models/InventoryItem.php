<?php

namespace App\Models;

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
}
