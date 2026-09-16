<?php

namespace App\Models;

use App\Models\Relations\GlobalOrOwnedBelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MaintenanceDocument extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'manufacturer_id', 'machine_model_id', 'title', 'document_type', 'file_reference', 'version', 'uploaded_by', 'is_active', 'archived_at'];

    protected $casts = ['is_active' => 'boolean', 'archived_at' => 'datetime'];

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
}
