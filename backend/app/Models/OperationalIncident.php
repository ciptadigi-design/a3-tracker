<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OperationalIncident extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['occurred_at' => 'datetime', 'material_loss' => 'decimal:2', 'service_loss' => 'decimal:2', 'base_amount' => 'decimal:2', 'penalty_multiplier' => 'decimal:4', 'assessed_loss' => 'decimal:2', 'resolved_at' => 'datetime', 'voided_at' => 'datetime'];

    protected static function booted()
    {
        static::creating(fn ($m) => $m->id ??= Str::uuid());
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function operator()
    {
        return $this->belongsTo(OperationalPerson::class, 'operator_person_id');
    }

    public function responsible()
    {
        return $this->belongsTo(OperationalPerson::class, 'responsible_person_id');
    }
}
