<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::creating(function (self $user) {
            $user->id ??= (string) Str::uuid();
        });
    }

    /**
     * Password reset and disable/re-enable atomically revoke every earlier session.
     * Use a SQL increment so concurrent resets cannot lose a version increment.
     */
    public function save(array $options = [])
    {
        $revoke = $this->exists && ($this->isDirty('password') || $this->isDirty('status'));
        if ($revoke) {
            $this->session_version = DB::raw('session_version + 1');
        }
        $saved = parent::save($options);
        if ($saved && $revoke) {
            $this->refresh();
        }

        return $saved;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'session_version',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function memberships()
    {
        return $this->hasMany(AccountMembership::class);
    }

    public function platformPrivilege()
    {
        return $this->hasOne(PlatformUserPrivilege::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
