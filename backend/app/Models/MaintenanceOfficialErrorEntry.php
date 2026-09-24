<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MaintenanceOfficialErrorEntry extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'document_id', 'ingestion_run_id', 'code', 'variant_key', 'section_number', 'classification',
        'cause', 'alert_measure', 'correction', 'warning', 'note', 'isolation_dipsw',
        'detached_control', 'source_page_start', 'source_page_end', 'raw_source_text',
        'source_hash', 'normalized_digest',
    ];

    protected $casts = [
        'source_page_start' => 'integer',
        'source_page_end' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $entry) => $entry->id ??= (string) Str::uuid());
        static::saving(function (self $entry) {
            $entry->code = strtoupper(trim($entry->code));
            $entry->variant_key = self::normalizeVariantKey($entry->variant_key);
            if ($entry->code === '' || $entry->variant_key === '') {
                throw new \InvalidArgumentException('Official error code and variant identity cannot be empty.');
            }
            if ($entry->source_page_start !== null && $entry->source_page_end !== null && $entry->source_page_start > $entry->source_page_end) {
                throw new \InvalidArgumentException('Official error source page range is invalid.');
            }
            $entry->source_hash = self::hashSourceText($entry->raw_source_text);
        });
    }

    public static function normalizeVariantKey(string $value): string
    {
        $normalized = preg_replace('/[^A-Z0-9-]+/', '_', strtoupper(trim($value))) ?? '';

        return trim(preg_replace('/_+/', '_', $normalized) ?? '', '_');
    }

    /** SHA-256 of the exact UTF-8 bytes stored in raw_source_text, without rewriting whitespace. */
    public static function hashSourceText(string $rawSourceText): string
    {
        return hash('sha256', $rawSourceText);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $accountIds = $user->memberships()->where('status', 'active')->pluck('account_id');

        return $query->whereHas('document', fn (Builder $documents) => $documents
            ->whereNull('account_id')
            ->orWhereIn('account_id', $accountIds));
    }

    public function document()
    {
        return $this->belongsTo(MaintenanceDocument::class, 'document_id');
    }

    public function ingestionRun()
    {
        return $this->belongsTo(MaintenanceOfficialIngestionRun::class, 'ingestion_run_id');
    }

    public function applicabilities()
    {
        return $this->hasMany(MaintenanceOfficialErrorApplicability::class, 'error_entry_id')->orderBy('sequence')->orderBy('id');
    }

    public function parts()
    {
        return $this->hasMany(MaintenanceOfficialErrorPart::class, 'error_entry_id')->orderByRaw('sequence IS NULL')->orderBy('sequence')->orderBy('id');
    }

    public function steps()
    {
        return $this->hasMany(MaintenanceOfficialErrorStep::class, 'error_entry_id')->orderBy('step_number')->orderBy('id');
    }

    public function references()
    {
        return $this->hasMany(MaintenanceOfficialErrorReference::class, 'error_entry_id')
            ->orderByRaw('step_number IS NOT NULL')
            ->orderBy('step_number')
            ->orderBy('reference_type')
            ->orderBy('reference_value')
            ->orderBy('id');
    }
}
