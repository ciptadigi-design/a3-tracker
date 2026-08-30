<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Support\Str;
class Branch extends Model { public $incrementing=false; protected $keyType='string'; protected $fillable=['account_id','code','name','timezone','is_active','archived_at']; protected static function booted(): void { static::creating(fn(self $m)=>$m->id ??= (string)Str::uuid()); } public function account(){return $this->belongsTo(Account::class);} }
