<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class FifoAllocation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'outbound_movement_id', 'fifo_layer_id', 'quantity', 'unit_cost', 'allocated_cost', 'allocation_order'];

    protected static function booted()
    {
        static::creating(fn ($m) => $m->id ??= Str::uuid());
    }
}
