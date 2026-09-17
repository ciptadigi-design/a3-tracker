<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MaintenanceDocumentPage extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['document_id', 'page_number', 'raw_text', 'metadata'];

    protected $casts = ['page_number' => 'integer', 'metadata' => 'array'];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    public function document()
    {
        return $this->belongsTo(MaintenanceDocument::class, 'document_id');
    }
}
