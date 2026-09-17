<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MaintenanceDocumentExtraction extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['document_id', 'status', 'total_pages', 'processed_pages', 'error_message', 'started_at', 'completed_at', 'created_by'];

    protected $casts = ['total_pages' => 'integer', 'processed_pages' => 'integer', 'started_at' => 'datetime', 'completed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    public function document()
    {
        return $this->belongsTo(MaintenanceDocument::class, 'document_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
