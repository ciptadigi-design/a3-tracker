<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MaintenanceOfficialErrorStep extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['error_entry_id', 'step_number', 'instruction', 'applicability_label', 'requires_technician'];

    protected $casts = ['step_number' => 'integer', 'requires_technician' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(fn (self $step) => $step->id ??= (string) Str::uuid());
        static::saving(function (self $step) {
            $step->instruction = trim($step->instruction);
            $label = $step->applicability_label;
            $step->applicability_label = $label === null || trim($label) === '' ? null : trim($label);
        });
    }

    public function errorEntry()
    {
        return $this->belongsTo(MaintenanceOfficialErrorEntry::class, 'error_entry_id');
    }
}
