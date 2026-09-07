<?php

namespace App\Services;

use App\Models\CounterReading;
use Illuminate\Support\Collection;

/**
 * Single canonical source for "what is the effective counter sequence".
 *
 * Usage is always derived dynamically from chronological order among
 * status=effective readings (observed_at, then created_at, then id as a
 * deterministic tie-break for same-instant readings) rather than trusting a
 * stored previous_reading_id pointer. A correction or void therefore never
 * needs to re-thread pointers on other rows: voiding/superseding a reading
 * removes it from this query and every neighbour's usage is recomputed the
 * next time this is called. Machine Cost totals, Daily Click Trend, and
 * Counter History all read through this one class so they can never
 * disagree after a correction or void.
 */
class EffectiveCounterSequence
{
    /**
     * @return Collection<int, CounterReading> effective readings in chronological
     *         order, each with ->usage (float, or null for the first reading)
     */
    public function forMachine(string $machineId, string $counterTypeId): Collection
    {
        $rows = CounterReading::where('machine_id', $machineId)
            ->where('counter_type_id', $counterTypeId)
            ->where('status', 'effective')
            ->orderBy('observed_at')->orderBy('created_at')->orderBy('id')
            ->get();

        $previousValue = null;
        foreach ($rows as $row) {
            $row->usage = $previousValue === null ? null : (float) $row->reading_value - $previousValue;
            $previousValue = (float) $row->reading_value;
        }

        return $rows;
    }

    /**
     * Simulates the resulting sequence if $targetId were replaced by a reading
     * with $value at $observedAt (both optional overrides — pass the target's
     * current values to simulate a void-free no-op). $targetId is excluded from
     * the base set and the simulated row is inserted at its chronological
     * position using $simulatedId/$simulatedCreatedAt as tie-breakers so the
     * check matches exactly how the real insert will be ordered.
     *
     * @return array{sequence: Collection, minUsage: ?float} minUsage is the lowest
     *         usage value produced (null if the simulated row is the only one)
     */
    public function simulateReplacement(string $machineId, string $counterTypeId, string $targetId, float $value, string $observedAt, string $simulatedId, string $simulatedCreatedAt): array
    {
        $rows = CounterReading::where('machine_id', $machineId)
            ->where('counter_type_id', $counterTypeId)
            ->where('status', 'effective')
            ->where('id', '!=', $targetId)
            ->get();

        $simulated = (object) [
            'id' => $simulatedId,
            'reading_value' => $value,
            'observed_at' => $observedAt,
            'created_at' => $simulatedCreatedAt,
        ];

        $combined = $rows->push($simulated)->all();
        usort($combined, fn ($a, $b) => strcmp((string) $a->observed_at, (string) $b->observed_at) ?: (strcmp((string) $a->created_at, (string) $b->created_at) ?: strcmp((string) $a->id, (string) $b->id)));
        $sequence = collect($combined);

        $previousValue = null;
        $usages = [];
        foreach ($sequence as $row) {
            $usage = $previousValue === null ? null : (float) $row->reading_value - $previousValue;
            if ($usage !== null) {
                $usages[] = $usage;
            }
            $previousValue = (float) $row->reading_value;
        }

        return ['sequence' => $sequence, 'minUsage' => $usages === [] ? null : min($usages)];
    }
}
