<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProductionScheduleException extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'branch_id', 'machine_id', 'exception_date', 'type', 'is_operating', 'override_start_time', 'override_end_time', 'crosses_midnight', 'note', 'created_by'];

    protected $casts = ['exception_date' => 'date', 'is_operating' => 'boolean', 'crosses_midnight' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(function ($m) {
            $m->id ??= (string) Str::uuid();
        });
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }
}
