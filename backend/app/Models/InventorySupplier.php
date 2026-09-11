<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class InventorySupplier extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'code', 'name', 'contact_name', 'phone', 'email', 'address', 'notes', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    // The frontend's supplier dialog and purchase picker still use the pre-Laravel-port
    // field names (supplier_code / contact_person); expose them as read-only aliases so
    // both naming generations resolve to the same canonical columns without a data migration.
    protected $appends = ['supplier_code', 'contact_person'];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    public function branchAssignments()
    {
        return $this->hasMany(SupplierBranchAssignment::class, 'supplier_id');
    }

    public function getSupplierCodeAttribute()
    {
        return $this->code;
    }

    public function getContactPersonAttribute()
    {
        return $this->contact_name;
    }
}
