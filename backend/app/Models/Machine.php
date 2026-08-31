<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Machine extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'branch_id', 'machine_model_id', 'machine_code', 'display_name', 'serial_number', 'installed_on', 'status', 'timezone', 'notes'];

    protected $casts = ['installed_on' => 'date'];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->id ??= Str::uuid());
        static::saving(fn ($m) => $m->machine_code = strtoupper(trim($m->machine_code)));
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function model()
    {
        return $this->belongsTo(MachineModel::class, 'machine_model_id');
    }

    public function counters()
    {
        return $this->hasMany(CounterReading::class);
    }

    public function components()
    {
        return $this->hasMany(MachineComponent::class);
    }
}
