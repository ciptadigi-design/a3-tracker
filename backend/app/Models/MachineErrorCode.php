<?php

namespace App\Models;

use App\Models\Relations\GlobalOrOwnedBelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MachineErrorCode extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'machine_model_id', 'code', 'title', 'category', 'severity', 'manufacturer_description', 'operator_description', 'official_solution', 'solution_summary', 'source_document_id', 'is_active', 'archived_at'];

    protected $casts = ['is_active' => 'boolean', 'archived_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function ($m) {
            $m->id ??= (string) Str::uuid();
            $m->code = strtoupper(trim($m->code));
        });
    }

    public function machineModel()
    {
        return new GlobalOrOwnedBelongsTo($this->newRelatedInstance(MachineModel::class)->newQuery(), $this, 'machine_model_id', 'machineModel');
    }

    public function sourceDocument()
    {
        return $this->belongsTo(MaintenanceDocument::class, 'source_document_id');
    }

    public function tickets()
    {
        return $this->hasMany(MaintenanceTicket::class, 'error_code_id');
    }

    public function solutions()
    {
        return $this->hasMany(MaintenanceErrorSolution::class, 'machine_error_code_id')->orderBy('step_number');
    }
}
