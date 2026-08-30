<?php

namespace App\Services;

use App\Models\CounterReading;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CorrectCounterReading
{
    public function __construct(private AccountAccessResolver $accounts) {}

    public function execute(User $actor, CounterReading $reading, array $data): CounterReading
    {
        $reading->loadMissing('machine.account');
        if (! $this->accounts->canManageOperational($actor, $reading->machine->account)) {
            abort(403);
        }

        return DB::transaction(function () use ($actor, $reading, $data) {
            $target = CounterReading::whereKey($reading->id)->lockForUpdate()->firstOrFail();
            $existing = ! empty($data['client_request_id']) ? CounterReading::where('account_id', $target->account_id)->where('client_request_id', $data['client_request_id'])->first() : null;
            if ($existing) {
                if ((string) $existing->corrects_reading_id === (string) $target->id && (float) $existing->reading_value === (float) ($data['replacement_value'] ?? $existing->reading_value)) {
                    return $existing;
                }
                throw new ConflictHttpException('client request id was already used for a different correction');
            }
            $reason = trim((string) ($data['correction_reason'] ?? ''));
            if ($reason === '') {
                throw ValidationException::withMessages(['correction_reason' => 'A correction reason is required.']);
            }
            if ($target->status !== 'effective') {
                throw new ConflictHttpException('only an effective reading can be corrected');
            }
            $latest = CounterReading::where('machine_id', $target->machine_id)->where('counter_type_id', $target->counter_type_id)->where('status', 'effective')->orderByDesc('observed_at')->orderByDesc('created_at')->orderByDesc('id')->lockForUpdate()->first();
            if (! $latest || (string) $latest->id !== (string) $target->id) {
                throw new ConflictHttpException('only the latest effective reading can be corrected');
            }
            if (($data['replacement_value'] ?? null) === null) {
                $target->update(['status' => 'voided', 'correction_reason' => $reason]);

                return $target->fresh();
            }
            if (empty($data['client_request_id'])) {
                throw ValidationException::withMessages(['client_request_id' => 'A client request ID is required for replacement corrections.']);
            }
            $value = (float) $data['replacement_value'];
            if ($value < 0 || ($target->previous_reading_id && $value < (float) CounterReading::find($target->previous_reading_id)->reading_value)) {
                throw ValidationException::withMessages(['replacement_value' => 'Replacement counter must not regress.']);
            }
            $target->update(['status' => 'superseded', 'correction_reason' => $reason]);

            return CounterReading::create([
                'account_id' => $target->account_id, 'machine_id' => $target->machine_id, 'counter_type_id' => $target->counter_type_id,
                'reading_value' => $data['replacement_value'], 'observed_at' => CarbonImmutable::parse($target->observed_at)->utc(), 'shift_code' => $target->shift_code,
                'operator_person_id' => $target->operator_person_id, 'operator_name_snapshot' => $target->operator_name_snapshot, 'entered_by' => $actor->id,
                'source' => 'correction', 'previous_reading_id' => $target->previous_reading_id, 'corrects_reading_id' => $target->id,
                'status' => 'effective', 'notes' => $data['replacement_notes'] ?? $target->notes, 'correction_reason' => $reason, 'client_request_id' => $data['client_request_id'],
            ]);
        });
    }
}
