<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MaintenanceOfficialErrorReference extends Model
{
    public const TYPES = ['WIRING_DIAGRAM', 'IO_CHECK', 'SERVICE_SECTION', 'DIPSW', 'OTHER'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['error_entry_id', 'step_number', 'reference_type', 'reference_value', 'page_number', 'section_number'];

    protected $casts = ['step_number' => 'integer', 'page_number' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn (self $reference) => $reference->id ??= (string) Str::uuid());
        static::saving(function (self $reference) {
            $reference->reference_type = strtoupper(trim($reference->reference_type));
            $reference->reference_value = trim($reference->reference_value);
            if (! in_array($reference->reference_type, self::TYPES, true)) {
                throw new InvalidArgumentException('Unsupported official error reference type.');
            }
            if ($reference->step_number !== null && ! MaintenanceOfficialErrorStep::where('error_entry_id', $reference->error_entry_id)->where('step_number', $reference->step_number)->exists()) {
                throw new InvalidArgumentException('The referenced solution step must belong to the same official error entry.');
            }
        });
    }

    public function errorEntry()
    {
        return $this->belongsTo(MaintenanceOfficialErrorEntry::class, 'error_entry_id');
    }
}
