<?php

namespace App\Services;

use App\Models\CounterReading;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CorrectCounterReading
{
    public function __construct(private AccountAccessResolver $accounts, private EffectiveCounterSequence $sequence) {}

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
                $sameValue = (float) $existing->reading_value === (float) ($data['replacement_value'] ?? $existing->reading_value);
                $sameTime = empty($data['replacement_observed_at']) || CarbonImmutable::parse($existing->observed_at)->eq(CarbonImmutable::parse($data['replacement_observed_at']));
                if ((string) $existing->corrects_reading_id === (string) $target->id && $sameValue && $sameTime) {
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
            if (($data['replacement_value'] ?? null) === null && ($data['replacement_observed_at'] ?? null) === null) {
                $target->update(['status' => 'voided', 'correction_reason' => $reason]);

                return $target->fresh();
            }
            if (empty($data['client_request_id'])) {
                throw ValidationException::withMessages(['client_request_id' => 'A client request ID is required for replacement corrections.']);
            }

            $value = array_key_exists('replacement_value', $data) && $data['replacement_value'] !== null ? (float) $data['replacement_value'] : (float) $target->reading_value;
            if ($value < 0) {
                throw ValidationException::withMessages(['replacement_value' => 'Replacement counter must be zero or greater.']);
            }

            $observed = ! empty($data['replacement_observed_at']) ? CarbonImmutable::parse($data['replacement_observed_at'])->utc() : CarbonImmutable::parse($target->observed_at)->utc();
            if ($observed->gt(CarbonImmutable::now('UTC')->addMinutes(5))) {
                throw ValidationException::withMessages(['replacement_observed_at' => 'Corrected date and time cannot be in the future.']);
            }

            $simulatedId = (string) Str::uuid();
            $simulation = $this->sequence->simulateReplacement($target->machine_id, $target->counter_type_id, $target->id, $value, $observed->format('Y-m-d H:i:s'), $simulatedId, CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'));
            if ($simulation['minUsage'] !== null && $simulation['minUsage'] < 0) {
                throw ValidationException::withMessages(['replacement_value' => 'This correction would make the counter regress against its resulting chronological neighbour. Adjust the value or the effective date/time.']);
            }

            $target->update(['status' => 'superseded', 'correction_reason' => $reason]);

            return CounterReading::create([
                'account_id' => $target->account_id, 'machine_id' => $target->machine_id, 'counter_type_id' => $target->counter_type_id,
                'reading_value' => $value, 'observed_at' => $observed, 'shift_code' => $target->shift_code,
                'operator_person_id' => $target->operator_person_id, 'operator_name_snapshot' => $target->operator_name_snapshot, 'entered_by' => $actor->id,
                'source' => 'correction', 'previous_reading_id' => $target->previous_reading_id, 'corrects_reading_id' => $target->id,
                'status' => 'effective', 'notes' => $data['replacement_notes'] ?? $target->notes, 'correction_reason' => $reason, 'client_request_id' => $data['client_request_id'],
            ]);
        });
    }
}
