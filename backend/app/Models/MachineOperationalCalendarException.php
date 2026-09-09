<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MachineOperationalCalendarException extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'machine_operational_calendar_exceptions';

    protected $fillable = ['account_id', 'branch_id', 'machine_id', 'calendar_date', 'exception_type', 'notes', 'excluded_from_target', 'client_request_id', 'created_by', 'updated_by'];

    protected $casts = ['calendar_date' => 'date', 'excluded_from_target' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->id ??= Str::uuid());
    }
}
