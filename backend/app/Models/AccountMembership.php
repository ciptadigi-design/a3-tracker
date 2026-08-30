<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Support\Str;
class AccountMembership extends Model { public $incrementing=false; protected $keyType='string'; protected $fillable=['account_id','user_id','role','status']; protected static function booted(): void { static::creating(fn(self $m)=>$m->id ??= (string)Str::uuid()); } public function account(){return $this->belongsTo(Account::class);} public function user(){return $this->belongsTo(User::class);} }
