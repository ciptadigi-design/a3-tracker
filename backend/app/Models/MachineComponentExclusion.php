<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MachineComponentExclusion extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'machine_id', 'profile_slot_id', 'slot_code', 'reason', 'cleared_by', 'cleared_at', 'client_request_id'];

    protected $casts = ['cleared_at' => 'datetime'];

    protected static function booted()
    {
        static::creating(fn ($m) => $m->id ??= Str::uuid());
    }
}
