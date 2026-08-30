<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Branch extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'code', 'name', 'address', 'timezone', 'notes', 'is_active', 'archived_at'];

    protected $casts = ['is_active' => 'boolean', 'archived_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            $m->id ??= (string) Str::uuid();
            $m->code = strtoupper(trim($m->code));
        });
        static::updating(function (self $m) {
            if ($m->isDirty('code')) {
                $m->code = strtoupper(trim($m->code));
            }if ($m->is_active === false && $m->archived_at === null) {
                $m->archived_at = now();
            }if ($m->is_active !== false && $m->isDirty('is_active')) {
                $m->archived_at = null;
            }
        });
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function machines()
    {
        return $this->hasMany(Machine::class);
    }
}
