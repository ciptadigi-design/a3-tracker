<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DowntimeEvent extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'branch_id', 'machine_id', 'cause', 'error_code_text', 'description', 'actual_start_at', 'actual_end_at', 'resolution_note', 'created_by', 'closed_by', 'is_backdated', 'active_key', 'client_request_id'];

    protected $casts = ['actual_start_at' => 'datetime', 'actual_end_at' => 'datetime', 'is_backdated' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(function ($m) {
            $m->id ??= (string) Str::uuid();
        });
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function notes()
    {
        return $this->hasMany(DowntimeNote::class, 'downtime_event_id')->orderBy('occurred_at');
    }

    public function maintenanceTicket()
    {
        return $this->hasOne(MaintenanceTicket::class, 'downtime_event_id');
    }

    public function isActive(): bool
    {
        return $this->actual_end_at === null;
    }
}
