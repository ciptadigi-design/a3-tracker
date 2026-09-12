<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Machine;
use App\Services\AccountAccessResolver;
use App\Services\BranchAccessResolver;
use App\Services\OperationalReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportsController extends Controller
{
    public function __construct(private OperationalReportService $reports, private AccountAccessResolver $accounts, private BranchAccessResolver $branches) {}

    // M2.19: the widest single report period allowed. Comfortably covers a
    // full calendar year (including a leap year) for day/week/month/
    // period-comparison workflows and year-over-year review, while still
    // rejecting an effectively-unbounded custom range (e.g. "since the
    // account was created"). Chosen from the actual query shape, not
    // arbitrarily: incidents(), counterRows(), and replacements() in
    // OperationalReportService currently load the ENTIRE account/machine
    // history unconditionally and filter by date in PHP afterward (only
    // inventoryConsumption() pushes the date range into SQL) - so period
    // WIDTH does not by itself bound the query cost today. This validation
    // is a predictable, user-facing guardrail against an extreme request,
    // not a fix for that deeper always-loads-full-history shape, which is a
    // larger change out of this milestone's scope.
    private const MAX_PERIOD_DAYS = 366;

    public function __invoke(Request $request)
    {
        $v = $request->validate([
            'account_id' => 'required|uuid', 'branch_id' => 'nullable|uuid', 'machine_id' => 'nullable|uuid',
            'period_start' => 'required|date_format:Y-m-d',
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start', function ($attribute, $value, $fail) use ($request) {
                $start = $request->input('period_start');
                // period_start has its own date_format:Y-m-d rule, but validation
                // rules for different fields can run in any order - guard against
                // a malformed period_start reaching Carbon::createFromFormat()
                // here and throwing instead of failing cleanly with a 422.
                if (! is_string($start) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
                    return;
                }
                $days = Carbon::createFromFormat('Y-m-d', $start)->diffInDays(Carbon::createFromFormat('Y-m-d', $value));
                if ($days > self::MAX_PERIOD_DAYS) {
                    $fail('The report period cannot exceed '.self::MAX_PERIOD_DAYS.' days. Narrow the date range and try again.');
                }
            }],
            'category' => 'nullable|string', 'status' => 'nullable|string',
        ]);
        $account = Account::findOrFail($v['account_id']);
        abort_unless($this->accounts->canAccess($request->user(), $account), 403);
        $branch = ! empty($v['branch_id']) ? Branch::where('id', $v['branch_id'])->where('account_id', $account->id)->firstOrFail() : null;
        if ($branch) {
            abort_unless($this->branches->canAccess($request->user(), $branch), 403);
        }
        if (! empty($v['machine_id'])) {
            $machine = Machine::with('branch')->where('id', $v['machine_id'])->where('account_id', $account->id)->firstOrFail();
            abort_unless(! $branch || $machine->branch_id === $branch->id, 422);
            abort_unless($machine->branch && $this->branches->canAccess($request->user(), $machine->branch), 403);
        }

        return response()->json($this->reports->build($account->id, $branch?->id, $v['machine_id'] ?? null, $v['period_start'], $v['period_end'], $v['category'] ?? null, $v['status'] ?? null));
    }
}
