<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OperationalPersonBranch extends Model
{
    protected $table = 'operational_person_branches';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'person_id', 'branch_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->id ??= Str::uuid());
    }
}
