<?php

namespace App\Services;

use App\Models\CounterReading;
use App\Models\Machine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CreateCounterReading
{
    public function __construct(private MachineAccessResolver $access, private OperationalPersonEligibilityService $people) {}

    public function execute(User $actor, Machine $machine, array $data): CounterReading
    {
        if (! $this->access->canAccess($actor, $machine, true)) {
            abort(403);
        }

        return DB::transaction(function () use ($actor, $machine, $data) {
            $machine = Machine::whereKey($machine->id)->lockForUpdate()->firstOrFail();
            $existing = CounterReading::where('account_id', $machine->account_id)->where('client_request_id', $data['client_request_id'])->first();
            if ($existing) {
                $same = (float) $existing->reading_value === (float) $data['reading_value'] && (string) $existing->machine_id === (string) $machine->id && (string) $existing->operator_person_id === (string) $data['operator_person_id'];
                if (! $same) {
                    throw new ConflictHttpException('client request id already processed with different values');
                }

                return $existing;
            } $person = $this->people->eligible($machine, $data['operator_person_id']);
            if (! $person) {
                throw ValidationException::withMessages(['operator_person_id' => 'Operator is not active or assigned to this branch.']);
            } $observed = CarbonImmutable::parse($data['observed_at'])->utc();
            if ($observed->gt(CarbonImmutable::now('UTC')->addMinutes(5))) {
                throw ValidationException::withMessages(['observed_at' => 'Observed time cannot be in the future.']);
            } $latest = CounterReading::where('machine_id', $machine->id)->where('counter_type_id', '00000000-0000-0000-0000-000000000001')->where('status', 'effective')->orderByDesc('observed_at')->orderByDesc('created_at')->orderByDesc('id')->lockForUpdate()->first();
            if ($latest && $observed->lt($latest->observed_at)) {
                throw new ConflictHttpException('observed time is older than the latest effective reading');
            } if ($latest && (float) $data['reading_value'] < (float) $latest->reading_value) {
                throw new ConflictHttpException('counter regression');
            }

            return CounterReading::create(['account_id' => $machine->account_id, 'machine_id' => $machine->id, 'counter_type_id' => '00000000-0000-0000-0000-000000000001', 'reading_value' => $data['reading_value'], 'observed_at' => $observed, 'shift_code' => $data['shift_code'] ?? null, 'operator_person_id' => $person->id, 'operator_name_snapshot' => $person->name, 'entered_by' => $actor->id, 'source' => 'manual', 'previous_reading_id' => $latest?->id, 'status' => 'effective', 'notes' => $data['notes'] ?? null, 'client_request_id' => $data['client_request_id']]);
        });
    }
}
