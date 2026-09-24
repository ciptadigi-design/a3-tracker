<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MaintenanceTicket extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'branch_id', 'machine_id', 'machine_component_id', 'error_code_id', 'official_error_entry_id', 'type', 'title', 'description', 'priority', 'status', 'reported_by', 'assigned_to', 'opened_at', 'started_at', 'resolved_at', 'client_request_id'];

    protected $casts = ['opened_at' => 'datetime', 'started_at' => 'datetime', 'resolved_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function ($m) {
            $m->id ??= (string) Str::uuid();
            $m->opened_at ??= now();
        });
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    public function machineComponent()
    {
        return $this->belongsTo(MachineComponent::class, 'machine_component_id');
    }

    public function errorCode()
    {
        return $this->belongsTo(MachineErrorCode::class, 'error_code_id');
    }

    public function officialErrorEntry()
    {
        return $this->belongsTo(MaintenanceOfficialErrorEntry::class, 'official_error_entry_id');
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function actions()
    {
        return $this->hasMany(MaintenanceAction::class, 'ticket_id');
    }
}
