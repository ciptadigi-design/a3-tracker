<?php

namespace App\Services;

use App\Models\MachineFinancialRate;

/**
 * Estimated financial impact of PRODUCTION downtime (never raw elapsed time):
 *   amount = production downtime hours x configured hourly rate, whole Rupiah.
 * A missing rate or missing production downtime yields null ("Belum dikonfigurasi"),
 * never 0. Operating cost and lost revenue are independent figures and are never summed.
 */
class DowntimeFinancialImpact
{
    public static function amount(?int $productionMinutes, ?int $hourlyRate): ?int
    {
        if ($productionMinutes === null || $hourlyRate === null) {
            return null;
        }

        return (int) round($productionMinutes * $hourlyRate / 60);
    }

    /** @return array{operating_cost_per_hour: ?int, estimated_revenue_per_hour: ?int, operating_cost_impact: ?int, potential_lost_revenue: ?int} */
    public static function forMachine(?MachineFinancialRate $rate, ?int $productionMinutes): array
    {
        return [
            'operating_cost_per_hour' => $rate?->operating_cost_per_hour,
            'estimated_revenue_per_hour' => $rate?->estimated_revenue_per_hour,
            'operating_cost_impact' => self::amount($productionMinutes, $rate?->operating_cost_per_hour),
            'potential_lost_revenue' => self::amount($productionMinutes, $rate?->estimated_revenue_per_hour),
        ];
    }

    /** Status of an aggregate over $total machines of which $rated have a rate and a schedule. */
    public static function status(int $rated, int $total): string
    {
        return $rated === 0 ? 'not_configured' : ($rated < $total ? 'partial' : 'configured');
    }
}
