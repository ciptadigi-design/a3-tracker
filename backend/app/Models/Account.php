<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Support\Str;
class Account extends Model { public $incrementing=false; protected $keyType='string'; protected $fillable=['code','name','default_timezone','default_currency','status','notes','archived_at']; protected static function booted(): void { static::creating(fn(self $m)=>$m->id ??= (string)Str::uuid()); } public function memberships(){return $this->hasMany(AccountMembership::class);} public function branches(){return $this->hasMany(Branch::class);} }
