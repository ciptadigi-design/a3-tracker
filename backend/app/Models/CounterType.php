<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CounterType extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'name', 'unit', 'decimal_scale', 'is_monotonic', 'is_active'];

    protected $casts = ['is_monotonic' => 'boolean', 'is_active' => 'boolean'];
}
