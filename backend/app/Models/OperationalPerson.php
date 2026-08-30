<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OperationalPerson extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'name', 'code', 'linked_user_id', 'is_active', 'notes', 'archived_at'];

    protected $casts = ['is_active' => 'boolean', 'archived_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->id ??= Str::uuid());
        static::saving(function ($m) {
            $m->name = trim($m->name);
            if (! $m->is_active) {
                $m->archived_at ??= now();
            } elseif ($m->isDirty('is_active')) {
                $m->archived_at = null;
            }
        });
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'operational_person_branches', 'person_id', 'branch_id')->withPivot(['account_id', 'is_active']);
    }
}
