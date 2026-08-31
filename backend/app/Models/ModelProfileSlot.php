<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ModelProfileSlot extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['profile_id', 'component_id', 'slot_code', 'slot_name', 'display_order', 'tracking_method', 'baseline_expected_clicks', 'is_active', 'archived_at'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted()
    {
        static::creating(fn ($m) => $m->id ??= Str::uuid());
        static::saving(fn ($m) => $m->slot_code = strtoupper(trim($m->slot_code)));
    }

    public function profile()
    {
        return $this->belongsTo(ModelProfile::class, 'profile_id');
    }

    public function component()
    {
        return $this->belongsTo(ComponentCatalog::class, 'component_id');
    }
}
