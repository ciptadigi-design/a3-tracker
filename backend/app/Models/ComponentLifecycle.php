<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ComponentLifecycle extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['machine_component_id', 'installed_counter', 'removed_counter', 'actual_usage', 'started_at', 'ended_at', 'status', 'evidence_level', 'source', 'notes', 'client_request_id', 'active_key'];

    protected $casts = ['installed_counter' => 'decimal:4', 'removed_counter' => 'decimal:4', 'actual_usage' => 'decimal:4', 'started_at' => 'datetime', 'ended_at' => 'datetime'];

    protected static function booted()
    {
        static::creating(fn ($m) => $m->id ??= Str::uuid());
    }

    public function machineComponent()
    {
        return $this->belongsTo(MachineComponent::class);
    }
}
