<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes a CounterReading to the frontend's field contract (reading_id,
 * previous_value, usage) so counter history rows carry the immutable
 * identity the UI needs to target a correction/void at the exact record —
 * never by inferring "latest" from value, timestamp, or array position.
 */
class CounterReadingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'reading_id' => $this->id,
            'account_id' => $this->account_id,
            'machine_id' => $this->machine_id,
            'counter_type_id' => $this->counter_type_id,
            'reading_value' => (float) $this->reading_value,
            'previous_value' => $this->resolvePreviousValue(),
            'usage' => $this->usage,
            'observed_at' => $this->observed_at,
            'shift_code' => $this->shift_code,
            'operator_person_id' => $this->operator_person_id,
            'operator_name_snapshot' => $this->operator_name_snapshot,
            'entered_by' => $this->entered_by,
            'source' => $this->source,
            'status' => $this->status,
            'notes' => $this->notes,
            'correction_reason' => $this->correction_reason,
            'previous_reading_id' => $this->previous_reading_id,
            'corrects_reading_id' => $this->corrects_reading_id,
            'client_request_id' => $this->client_request_id,
            'created_at' => $this->created_at,
        ];
    }

    private function resolvePreviousValue(): ?float
    {
        $attributes = $this->resource->getAttributes();
        if (array_key_exists('previous_value_override', $attributes)) {
            return $attributes['previous_value_override'] === null ? null : (float) $attributes['previous_value_override'];
        }

        $previous = $this->relationLoaded('previous') ? $this->previous : null;

        return $previous ? (float) $previous->reading_value : null;
    }
}
