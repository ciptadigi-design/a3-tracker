<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MachineClickTarget extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'machine_click_targets';

    protected $fillable = ['account_id', 'branch_id', 'machine_id', 'target_year', 'target_month', 'monthly_click_target', 'created_by', 'updated_by'];

    protected $casts = ['target_year' => 'integer', 'target_month' => 'integer', 'monthly_click_target' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->id ??= Str::uuid());
    }
}
