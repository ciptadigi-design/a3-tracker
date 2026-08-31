<?php

namespace App\Services;

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
}
