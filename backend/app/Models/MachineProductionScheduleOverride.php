<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MachineProductionScheduleOverride extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'branch_id', 'machine_id', 'day_of_week', 'is_operating', 'start_time', 'end_time', 'crosses_midnight'];

    protected $casts = ['is_operating' => 'boolean', 'crosses_midnight' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(function ($m) {
            $m->id ??= (string) Str::uuid();
        });
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }
}
