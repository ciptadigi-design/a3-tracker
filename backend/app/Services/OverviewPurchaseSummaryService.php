<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Branch-scoped purchasing value for Overview.
 *
 * A normal purchase is commercially committed as soon as PurchaseReceiptService
 * creates it in `draft`; receiving then advances it to `partially_received` or
 * `received`. Legacy imports deliberately share the draft status, so their
 * authoritative LEGACY_IMPORT note marker is excluded. Cancelled purchases are
 * not commercial commitments. Received value follows Purchasing's existing
 * lifetime-to-date receipt semantics for the same selected purchase population.
 */
class OverviewPurchaseSummaryService
{
    private const INCLUDED_STATUSES = ['draft', 'partially_received', 'received'];

    public function forBranchPeriod(string $accountId, string $branchId, string $from, string $to): array
    {
        $receivedByLine = DB::table('receipt_lines')
            ->select('purchase_line_id', DB::raw('SUM(quantity) as received_quantity'))
            ->where('account_id', $accountId)
            ->groupBy('purchase_line_id');

        $summary = DB::table('purchases as purchase')
            ->join('purchase_lines as line', function ($join) {
                $join->on('line.purchase_id', '=', 'purchase.id')
                    ->on('line.account_id', '=', 'purchase.account_id');
            })
            ->leftJoinSub($receivedByLine, 'received', fn ($join) => $join->on('received.purchase_line_id', '=', 'line.id'))
            ->where('purchase.account_id', $accountId)
            ->where('purchase.branch_id', $branchId)
            ->whereBetween('purchase.purchase_date', [$from, $to])
            ->whereIn('purchase.status', self::INCLUDED_STATUSES)
            ->where(fn ($query) => $query->whereNull('purchase.notes')->orWhere('purchase.notes', 'not like', 'LEGACY_IMPORT%'))
            ->selectRaw('COUNT(DISTINCT purchase.id) as purchase_count')
            ->selectRaw('COALESCE(SUM(line.ordered_quantity * COALESCE(line.unit_cost, 0)), 0) as purchase_value')
            ->selectRaw('COALESCE(SUM(COALESCE(received.received_quantity, 0) * COALESCE(line.unit_cost, 0)), 0) as received_value')
            ->first();

        $purchaseValue = round((float) ($summary->purchase_value ?? 0), 2);
        $receivedValue = round((float) ($summary->received_value ?? 0), 2);

        return [
            'purchase_value' => number_format($purchaseValue, 2, '.', ''),
            'purchase_count' => (int) ($summary->purchase_count ?? 0),
            'received_value' => number_format($receivedValue, 2, '.', ''),
            'received_percentage' => $purchaseValue > 0 ? round($receivedValue / $purchaseValue * 100, 2) : 0.0,
            'scope' => 'branch',
        ];
    }
}
