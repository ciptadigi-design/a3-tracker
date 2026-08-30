<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Account extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'name', 'default_timezone', 'default_currency', 'status', 'notes', 'archived_at'];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            $m->id ??= (string) Str::uuid();
            $m->code = strtoupper(trim($m->code));
        });
        static::updating(function (self $m) {
            if ($m->isDirty('code')) {
                $m->code = strtoupper(trim($m->code));
            }if ($m->status === 'archived' && $m->archived_at === null) {
                $m->archived_at = now();
            }if ($m->status !== 'archived' && $m->isDirty('status')) {
                $m->archived_at = null;
            }
        });
    }

    public function memberships()
    {
        return $this->hasMany(AccountMembership::class);
    }

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }
}
