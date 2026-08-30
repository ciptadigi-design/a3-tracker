<?php

namespace App\Services;

use App\Models\Machine;
use Carbon\CarbonImmutable;

class MachineTimezoneResolver
{
    public function resolve(Machine $machine): string
    {
        return $machine->timezone ?: ($machine->branch->timezone ?: ($machine->account->default_timezone ?: 'UTC'));
    }

    public function range(Machine $machine, string $from, string $to): array
    {
        $tz = $this->resolve($machine);

        return [CarbonImmutable::parse($from, $tz)->startOfDay()->utc(), CarbonImmutable::parse($to, $tz)->addDay()->startOfDay()->utc()];
    }
}
