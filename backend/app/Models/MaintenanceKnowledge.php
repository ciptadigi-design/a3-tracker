<?php

namespace App\Models;

use App\Models\Relations\GlobalOrOwnedBelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MaintenanceKnowledge extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'machine_model_id', 'error_code_id', 'source_ticket_id', 'problem', 'symptoms', 'solution', 'success_notes', 'approval_status', 'submitted_by', 'submitted_at', 'reviewed_by', 'reviewed_at', 'published_at'];

    protected $casts = ['submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'published_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    public function machineModel()
    {
        return new GlobalOrOwnedBelongsTo($this->newRelatedInstance(MachineModel::class)->newQuery(), $this, 'machine_model_id', 'machineModel');
    }

    public function errorCode()
    {
        return $this->belongsTo(MachineErrorCode::class, 'error_code_id');
    }

    public function sourceTicket()
    {
        return $this->belongsTo(MaintenanceTicket::class, 'source_ticket_id');
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
