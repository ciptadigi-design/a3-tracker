<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MachineModel extends Model
{
    protected $table = 'machine_models';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'manufacturer_id', 'model_code', 'name', 'machine_category', 'color_capability', 'description', 'notes', 'is_active', 'archived_at'];

    protected $casts = ['is_active' => 'boolean', 'archived_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->id ??= Str::uuid());
        static::saving(function ($m) {
            $m->model_code = strtolower(trim($m->model_code));
            $m->name = trim($m->name);
            if (! $m->is_active) {
                $m->archived_at ??= now();
            } elseif ($m->isDirty('is_active')) {
                $m->archived_at = null;
            }
        });
    }

    public function manufacturer()
    {
        return $this->belongsTo(Manufacturer::class);
    }

    public function machines()
    {
        return $this->hasMany(Machine::class);
    }
}
