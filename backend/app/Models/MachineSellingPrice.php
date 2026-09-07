<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MachineSellingPrice extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'machine_selling_prices';

    protected $fillable = ['account_id', 'machine_id', 'price_per_click', 'effective_from', 'notes', 'client_request_id', 'created_by', 'status', 'voided_at', 'voided_by', 'void_reason'];

    protected $casts = ['effective_from' => 'datetime', 'price_per_click' => 'decimal:4', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->id ??= Str::uuid());
    }
}
