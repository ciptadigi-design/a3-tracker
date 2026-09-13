<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Shared Reports/Overview date-only transport and maximum-span contract. */
class OperationalPeriodRange
{
    public const MAX_PERIOD_DAYS = 366;

    public static function rules(Request $request): array
    {
        return [
            'period_start' => 'required|date_format:Y-m-d',
            'period_end' => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:period_start', function ($attribute, $value, $fail) use ($request) {
                $start = $request->input('period_start');
                if (! is_string($start) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
                    return;
                }
                $days = Carbon::createFromFormat('Y-m-d', $start)->diffInDays(Carbon::createFromFormat('Y-m-d', $value));
                if ($days > self::MAX_PERIOD_DAYS) {
                    $fail('The report period cannot exceed '.self::MAX_PERIOD_DAYS.' days. Narrow the date range and try again.');
                }
            }],
        ];
    }
}
