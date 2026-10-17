<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DowntimeNote extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'downtime_event_id', 'performed_by', 'note', 'occurred_at', 'machine_component_id', 'component_replacement_id', 'client_request_id'];

    protected $casts = ['occurred_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function ($m) {
            $m->id ??= (string) Str::uuid();
            $m->occurred_at ??= now();
        });
    }

    public function event()
    {
        return $this->belongsTo(DowntimeEvent::class, 'downtime_event_id');
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function machineComponent()
    {
        return $this->belongsTo(MachineComponent::class);
    }

    public function componentReplacement()
    {
        return $this->belongsTo(ComponentReplacement::class);
    }
}
