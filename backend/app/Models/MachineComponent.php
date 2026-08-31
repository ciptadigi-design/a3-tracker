<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MachineComponent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'machine_id', 'component_id', 'profile_slot_id', 'slot_code', 'source_type', 'status', 'active_key', 'display_order'];

    protected static function booted()
    {
        static::creating(function ($m) {
            $m->id ??= Str::uuid();
            $m->active_key = $m->status === 'configured' ? 'active' : null;
        });
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    public function component()
    {
        return $this->belongsTo(ComponentCatalog::class);
    }

    public function profileSlot()
    {
        return $this->belongsTo(ModelProfileSlot::class, 'profile_slot_id');
    }

    public function lifecycles()
    {
        return $this->hasMany(ComponentLifecycle::class);
    }
}
