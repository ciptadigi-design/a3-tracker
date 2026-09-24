<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MaintenanceAssistedErrorEntry extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'content_version' => 'integer',
        'generated_at' => 'datetime',
        'validated_at' => 'datetime',
        'validation_errors' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $entry) => $entry->id ??= (string) Str::uuid());
    }

    public function officialEntry()
    {
        return $this->belongsTo(MaintenanceOfficialErrorEntry::class, 'official_error_entry_id');
    }

    public function steps()
    {
        return $this->hasMany(MaintenanceAssistedErrorStep::class, 'assisted_error_entry_id')->orderBy('official_order');
    }
}
