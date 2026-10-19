<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MaintenanceAction extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'ticket_id', 'component_replacement_id', 'action_description', 'result', 'performed_by', 'performed_at', 'operator_person_id', 'operator_name', 'external_technician_name', 'vendor_name', 'findings', 'corrects_action_id', 'correction_reason'];

    protected $casts = ['performed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function ($m) {
            $m->id ??= (string) Str::uuid();
            $m->performed_at ??= now();
        });
        // The activity log is append-only: history is never rewritten or removed.
        static::updating(fn () => throw new \LogicException('Maintenance activity history is append-only.'));
        static::deleting(fn () => throw new \LogicException('Maintenance activity history is append-only.'));
    }

    public function original()
    {
        return $this->belongsTo(self::class, 'corrects_action_id');
    }

    public function ticket()
    {
        return $this->belongsTo(MaintenanceTicket::class, 'ticket_id');
    }

    public function componentReplacement()
    {
        return $this->belongsTo(ComponentReplacement::class, 'component_replacement_id');
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
