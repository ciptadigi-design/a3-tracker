<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MaintenanceOfficialIngestionRun extends Model
{
    public const STATUS_APPLYING = 'APPLYING';

    public const STATUS_COMPLETED = 'COMPLETED';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $run) => $run->id ??= (string) Str::uuid());
        static::saving(function (self $run) {
            if (! in_array($run->status, [self::STATUS_APPLYING, self::STATUS_COMPLETED], true)) {
                throw new InvalidArgumentException('Unsupported official ingestion run status.');
            }
        });
    }

    public function document()
    {
        return $this->belongsTo(MaintenanceDocument::class, 'document_id');
    }

    public function entries()
    {
        return $this->hasMany(MaintenanceOfficialErrorEntry::class, 'ingestion_run_id');
    }
}
