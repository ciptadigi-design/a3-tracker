<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model; use Illuminate\Support\Str;
class Branch extends Model { public $incrementing=false; protected $keyType='string'; protected $fillable=['account_id','code','name','address','timezone','notes','is_active','archived_at']; protected $casts=['is_active'=>'boolean','archived_at'=>'datetime']; protected static function booted(): void { static::creating(fn(self $m)=>$m->id ??= (string)Str::uuid()); } public function account(){return $this->belongsTo(Account::class);} }
