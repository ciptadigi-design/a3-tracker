<?php

namespace App\Models;

use App\Models\Relations\GlobalOrOwnedBelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ModelProfile extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'machine_model_id', 'name', 'is_active', 'archived_at'];

    protected $casts = ['is_active' => 'boolean', 'archived_at' => 'datetime'];

    protected static function booted()
    {
        static::creating(fn ($m) => $m->id ??= Str::uuid());
    }

    public function slots()
    {
        return $this->hasMany(ModelProfileSlot::class, 'profile_id');
    }

    public function machineModel()
    {
        return new GlobalOrOwnedBelongsTo($this->newRelatedInstance(MachineModel::class)->newQuery(), $this, 'machine_model_id', 'machineModel');
    }
}
