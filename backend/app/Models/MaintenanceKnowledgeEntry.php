<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MaintenanceKnowledgeEntry extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['import_id', 'extraction_id', 'knowledge_type', 'code', 'normalized_code', 'title', 'category', 'severity', 'description', 'operator_solution', 'technician_solution', 'page_reference', 'source_page_start', 'source_page_end', 'evidence', 'collision_status', 'status', 'created_by', 'approved_by', 'published_at'];

    protected $casts = ['published_at' => 'datetime', 'source_page_start' => 'integer', 'source_page_end' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    public function import()
    {
        return $this->belongsTo(MaintenanceDocumentImport::class, 'import_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function extraction()
    {
        return $this->belongsTo(MaintenanceDocumentExtraction::class, 'extraction_id');
    }
}
