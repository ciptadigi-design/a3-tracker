<?php

namespace App\Services;

use App\Models\InventoryLocation;
use App\Models\Machine;
use App\Models\OperationalPerson;

class OperationalPersonEligibilityService
{
    public function forMachine(Machine $machine)
    {
        return OperationalPerson::where('account_id', $machine->account_id)->where('is_active', true)->whereHas('branches', fn ($q) => $q->where('branches.id', $machine->branch_id)->where('operational_person_branches.is_active', true)->where('operational_person_branches.can_record_counter', true))->orderBy('name')->get();
    }

    public function eligible(Machine $machine, string $id): ?OperationalPerson
    {
        return $this->forMachine($machine)->firstWhere('id', $id);
    }

    public function eligibleForBranch($branch, string $id, bool $counterOnly = false): ?OperationalPerson
    {
        return OperationalPerson::where('account_id', $branch->account_id)->where('id', $id)->where('is_active', true)->whereHas('branches', fn ($q) => $q->where('branches.id', $branch->id)->where('operational_person_branches.is_active', true)->when($counterOnly, fn ($q) => $q->where('operational_person_branches.can_record_counter', true)))->first();
    }

    /**
     * Canonical Operator population for a branch: the same is_active +
     * can_record_counter population Daily Click uses (see forMachine()).
     * This is the single reusable resolver Inventory and Component
     * Replacement must both consume instead of maintaining their own
     * disconnected people queries.
     */
    public function forBranch(string $accountId, string $branchId)
    {
        return OperationalPerson::where('account_id', $accountId)->where('is_active', true)
            ->with(['branchAssignments' => fn ($q) => $q->where('branch_id', $branchId)->where('is_active', true)->with('branch')])
            ->whereHas('branches', fn ($q) => $q->where('branches.id', $branchId)->where('operational_person_branches.is_active', true)->where('operational_person_branches.can_record_counter', true))
            ->orderBy('name')->get();
    }

    /**
     * Resolves a canonical Operator for the branch that owns an Inventory
     * Location. Falls back to any branch within the account when the
     * location itself is not branch-scoped (account-wide location).
     */
    public function eligibleOperatorForLocation(InventoryLocation $location, string $id): ?OperationalPerson
    {
        if ($location->branch_id) {
            return $this->forBranch($location->account_id, $location->branch_id)->firstWhere('id', $id);
        }

        return OperationalPerson::where('account_id', $location->account_id)->where('id', $id)->where('is_active', true)
            ->whereHas('branches', fn ($q) => $q->where('operational_person_branches.is_active', true)->where('operational_person_branches.can_record_counter', true))
            ->first();
    }
}
