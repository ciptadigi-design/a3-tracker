<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Manufacturer extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'code', 'name', 'notes', 'is_active', 'archived_at'];

    protected $casts = ['is_active' => 'boolean', 'archived_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->id ??= Str::uuid());
        static::saving(function ($m) {
            $m->code = strtolower(trim($m->code));
            $m->name = trim($m->name);
            if (! $m->is_active) {
                $m->archived_at ??= now();
            } elseif ($m->isDirty('is_active')) {
                $m->archived_at = null;
            }
        });
    }

    public function models()
    {
        return $this->hasMany(MachineModel::class);
    }
}
