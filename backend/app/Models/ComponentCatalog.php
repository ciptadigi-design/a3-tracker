<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ComponentCatalog extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'code', 'name', 'description', 'category', 'tracking_method', 'is_active', 'archived_at'];

    protected $casts = ['is_active' => 'boolean', 'archived_at' => 'datetime'];

    protected static function booted()
    {
        static::creating(fn ($m) => $m->id ??= Str::uuid());
        static::saving(fn ($m) => [$m->code = strtolower(trim($m->code)), $m->name = trim($m->name)]);
    }

    public function slots()
    {
        return $this->hasMany(ModelProfileSlot::class, 'component_id');
    }
}
