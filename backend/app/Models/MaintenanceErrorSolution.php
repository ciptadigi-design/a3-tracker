<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MaintenanceErrorSolution extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['machine_error_code_id', 'step_number', 'instruction', 'requires_technician', 'created_by'];

    protected $casts = ['requires_technician' => 'boolean', 'step_number' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    public function errorCode()
    {
        return $this->belongsTo(MachineErrorCode::class, 'machine_error_code_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
