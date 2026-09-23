<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MaintenanceOfficialErrorPart extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['error_entry_id', 'sequence', 'part_name', 'part_code', 'applicability_label'];

    protected $casts = ['sequence' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn (self $part) => $part->id ??= (string) Str::uuid());
        static::saving(function (self $part) {
            $part->part_name = trim($part->part_name);
            foreach (['part_code', 'applicability_label'] as $attribute) {
                $value = $part->{$attribute};
                $part->{$attribute} = $value === null || trim($value) === '' ? null : trim($value);
            }
        });
    }

    public function errorEntry()
    {
        return $this->belongsTo(MaintenanceOfficialErrorEntry::class, 'error_entry_id');
    }
}
