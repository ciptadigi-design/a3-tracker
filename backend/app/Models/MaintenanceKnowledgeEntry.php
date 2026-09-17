<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MaintenanceKnowledgeEntry extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['import_id', 'knowledge_type', 'code', 'title', 'category', 'severity', 'description', 'operator_solution', 'technician_solution', 'page_reference', 'status', 'created_by', 'approved_by', 'published_at'];

    protected $casts = ['published_at' => 'datetime'];

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
}
