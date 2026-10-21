<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MachineFinancialRate extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'machine_id', 'operating_cost_per_hour', 'estimated_revenue_per_hour', 'updated_by'];

    protected $casts = ['operating_cost_per_hour' => 'integer', 'estimated_revenue_per_hour' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }
}
