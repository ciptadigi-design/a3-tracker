<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MaintenanceErrorSolution extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['machine_error_code_id', 'step_number', 'applicability_label', 'instruction', 'requires_technician', 'created_by'];

    protected $casts = ['requires_technician' => 'boolean', 'step_number' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    /**
     * V1.8.1 - NULL means "generally applicable" (the pre-existing, unlabeled behavior); a stray
     * empty/whitespace-only value must never be stored as a distinct "empty label" identity, or it
     * would silently diverge from a genuinely NULL row under variant-identity comparison. Plain
     * text only - this is reviewer-authored data rendered as text, never interpreted as HTML.
     */
    public function setApplicabilityLabelAttribute(?string $value): void
    {
        $trimmed = $value === null ? null : trim($value);
        $this->attributes['applicability_label'] = $trimmed === '' ? null : $trimmed;
    }

    public function errorCode()
    {
        return $this->belongsTo(MachineErrorCode::class, 'machine_error_code_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
