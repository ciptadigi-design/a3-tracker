<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MachineClickTargetRevision extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $table = 'machine_click_target_revisions';

    protected $fillable = ['account_id', 'machine_id', 'target_year', 'target_month', 'previous_target', 'new_target', 'reason', 'changed_by', 'client_request_id', 'sequence', 'created_at'];

    protected $casts = ['target_year' => 'integer', 'target_month' => 'integer', 'previous_target' => 'integer', 'new_target' => 'integer', 'sequence' => 'integer', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function ($m) {
            $m->id ??= Str::uuid();
            $m->created_at ??= now();
        });
    }
}
