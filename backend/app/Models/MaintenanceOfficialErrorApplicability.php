<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MaintenanceOfficialErrorApplicability extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['error_entry_id', 'sequence', 'scope_type', 'scope_label', 'machine_model_id'];

    protected $casts = ['sequence' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn (self $row) => $row->id ??= (string) Str::uuid());
        static::saving(function (self $row) {
            $row->scope_type = strtoupper(trim($row->scope_type));
            $row->scope_label = trim($row->scope_label);
        });
    }

    public function errorEntry()
    {
        return $this->belongsTo(MaintenanceOfficialErrorEntry::class, 'error_entry_id');
    }

    public function machineModel()
    {
        return $this->belongsTo(MachineModel::class, 'machine_model_id');
    }
}
