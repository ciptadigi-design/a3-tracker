<?php

namespace App\Models;

use App\Models\Relations\GlobalOrOwnedBelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MaintenanceDocument extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'manufacturer_id', 'machine_model_id', 'title', 'description', 'document_type', 'file_reference', 'file_path', 'file_name', 'file_size', 'mime_type', 'storage_disk', 'uploaded_at', 'version', 'status', 'uploaded_by', 'is_active', 'archived_at'];

    protected $casts = ['is_active' => 'boolean', 'archived_at' => 'datetime', 'file_size' => 'integer', 'uploaded_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    public function manufacturer()
    {
        return new GlobalOrOwnedBelongsTo($this->newRelatedInstance(Manufacturer::class)->newQuery(), $this, 'manufacturer_id', 'manufacturer');
    }

    public function machineModel()
    {
        return new GlobalOrOwnedBelongsTo($this->newRelatedInstance(MachineModel::class)->newQuery(), $this, 'machine_model_id', 'machineModel');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function references()
    {
        return $this->hasMany(MaintenanceDocumentReference::class, 'document_id');
    }

    public function extractions()
    {
        return $this->hasMany(MaintenanceDocumentExtraction::class, 'document_id');
    }

    public function pages()
    {
        return $this->hasMany(MaintenanceDocumentPage::class, 'document_id');
    }

    public function officialErrorEntries()
    {
        return $this->hasMany(MaintenanceOfficialErrorEntry::class, 'document_id');
    }

    public function officialIngestionRuns()
    {
        return $this->hasMany(MaintenanceOfficialIngestionRun::class, 'document_id');
    }
}
