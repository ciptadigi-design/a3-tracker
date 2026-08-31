<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class InventoryLocation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'branch_id', 'code', 'name', 'is_active', 'archived_at'];

    protected static function booted()
    {
        static::creating(fn ($m) => $m->id ??= Str::uuid());
    }
}
