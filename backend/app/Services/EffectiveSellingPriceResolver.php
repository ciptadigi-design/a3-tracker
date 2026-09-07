<?php

namespace App\Services;

use App\Models\MachineSellingPrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Ports the Supabase oracle's price-resolution semantics
 * (get_machine_economics_period in
 * supabase/migrations/20260828001200_machine_selling_price_revenue_contribution.sql):
 * the effective price at any instant is the posted (non-voided) row with the
 * greatest effective_from at-or-before that instant, tie-broken by the
 * greatest created_at, then the greatest id. A voided row never resumes
 * being effective; if it is voided, whatever prior posted row was effective
 * before it resumes automatically because it is simply excluded here.
 */
class EffectiveSellingPriceResolver
{
    /** @return Collection<int, MachineSellingPrice> posted prices for the machine, ascending by (effective_from, created_at, id) */
    public function postedPrices(string $machineId): Collection
    {
        return MachineSellingPrice::where('machine_id', $machineId)
            ->where('status', 'posted')
            ->orderBy('effective_from')->orderBy('created_at')->orderBy('id')
            ->get();
    }

    /** Latest posted price with effective_from <= $at (inclusive). */
    public function effectiveAt(Collection $ascendingPostedPrices, $at): ?MachineSellingPrice
    {
        $at = Carbon::parse($at);
        $match = null;
        foreach ($ascendingPostedPrices as $price) {
            if ($price->effective_from->lte($at)) {
                $match = $price;
            } else {
                break;
            }
        }

        return $match;
    }

    /** Latest posted price with effective_from < $before (exclusive) — used for period-end resolution. */
    public function effectiveBefore(Collection $ascendingPostedPrices, $before): ?MachineSellingPrice
    {
        $before = Carbon::parse($before);
        $match = null;
        foreach ($ascendingPostedPrices as $price) {
            if ($price->effective_from->lt($before)) {
                $match = $price;
            } else {
                break;
            }
        }

        return $match;
    }
}
