<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\User;

class MachineAccessResolver
{
    public function __construct(private BranchAccessResolver $branches) {}

    public function canAccess(User $user, Machine $machine, bool $write = false): bool
    {
        if (! $machine->account || ! $machine->branch || $machine->account_id !== $machine->branch->account_id || ! $this->branches->canAccess($user, $machine->branch)) {
            return false;
        }

return ! $write || $machine->status === 'active';
    }
}
