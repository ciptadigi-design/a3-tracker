<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MaintenanceDocumentReference extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['document_id', 'machine_error_code_id', 'reference_type', 'page_number', 'section_title', 'notes', 'created_by'];

    protected $casts = ['page_number' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    public function document()
    {
        return $this->belongsTo(MaintenanceDocument::class, 'document_id');
    }

    public function errorCode()
    {
        return $this->belongsTo(MachineErrorCode::class, 'machine_error_code_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
