<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MaintenanceDocumentImport extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['document_id', 'extraction_id', 'machine_model_id', 'status', 'import_type', 'created_by', 'reviewed_by', 'reviewed_at', 'processing_started_at', 'processing_completed_at', 'pages_processed', 'candidate_count', 'processing_version'];

    protected $casts = ['reviewed_at' => 'datetime', 'processing_started_at' => 'datetime', 'processing_completed_at' => 'datetime', 'pages_processed' => 'integer', 'candidate_count' => 'integer', 'processing_version' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    public function document()
    {
        return $this->belongsTo(MaintenanceDocument::class, 'document_id');
    }

    // Plain belongsTo, not GlobalOrOwnedBelongsTo - machine_model_id is already
    // validated against the parent document's scope at creation time (ScopedReference::
    // activeGlobalOrOwned in the controller), the same "downstream reference, not a
    // catalog-scope boundary itself" treatment MaintenanceTicket::machine()/errorCode() use.
    public function machineModel()
    {
        return $this->belongsTo(MachineModel::class, 'machine_model_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function entries()
    {
        return $this->hasMany(MaintenanceKnowledgeEntry::class, 'import_id');
    }

    public function extraction()
    {
        return $this->belongsTo(MaintenanceDocumentExtraction::class, 'extraction_id');
    }
}
