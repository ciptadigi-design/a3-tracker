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

    // Canonical branch-eligibility rule (M2.17.4.1): a supplier with zero explicit
    // branch assignments is available to every branch in its account (legacy/default
    // behaviour, so existing suppliers never vanish from branches that never assigned
    // them); once assigned to at least one branch, it is only available in the
    // branches it was explicitly assigned to. Shared by the Purchase picker and the
    // Supplier Master list so the two views cannot drift apart again.
    public function scopeVisibleToBranch($query, string $branchId)
    {
        return $query->where(fn ($q) => $q->whereDoesntHave('branchAssignments')->orWhereHas('branchAssignments', fn ($q2) => $q2->where('branch_id', $branchId)));
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
