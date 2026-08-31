<?php

namespace App\Models;

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
        return $this->belongsTo(MachineModel::class, 'machine_model_id');
    }
}
