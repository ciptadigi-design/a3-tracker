<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MaintenanceAssistedErrorStep extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['official_order' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn (self $step) => $step->id ??= (string) Str::uuid());
    }
}
