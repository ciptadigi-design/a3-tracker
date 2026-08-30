<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AccountMembership extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'user_id', 'role', 'status', 'accepted_at'];

    protected $casts = ['accepted_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->id ??= (string) Str::uuid());
        static::saving(function (self $m) {
            if ($m->exists && ($m->isDirty('account_id') || $m->isDirty('user_id'))) {
                throw new ConflictHttpException('Membership identity is immutable.');
            } if ($m->exists && $m->getOriginal('role') === 'owner' && $m->getOriginal('status') === 'active' && ($m->role !== 'owner' || $m->status !== 'active') && ! self::where('account_id', $m->account_id)->where('role', 'owner')->where('status', 'active')->where('id', '<>', $m->id)->exists()) {
                throw new ConflictHttpException('The last active owner cannot be suspended or demoted.');
            }
        });
        static::deleting(function (self $m) {
            if ($m->role === 'owner' && $m->status === 'active' && ! self::where('account_id', $m->account_id)->where('role', 'owner')->where('status', 'active')->where('id', '<>', $m->id)->exists()) {
                throw new ConflictHttpException('The last active owner cannot be removed.');
            }
        });
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branchAssignments()
    {
        return $this->hasMany(AccountMembershipBranch::class, 'membership_id');
    }
}
