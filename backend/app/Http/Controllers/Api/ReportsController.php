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
use Illuminate\Validation\ValidationException;

class ReportsController extends Controller
{
    public function __construct(private OperationalReportService $reports, private AccountAccessResolver $accounts, private BranchAccessResolver $branches) {}

    // Preserve the established report range bound; branch scope is resolved server-side.
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
            abort_unless($machine->branch && $this->branches->canAccess($request->user(), $machine->branch), 403);
            if ($branch && $machine->branch_id !== $branch->id) {
                throw ValidationException::withMessages(['machine_id' => 'Machine is not in the selected branch.']);
            }
        }

        $allowed = $this->accounts->authorizedBranchIds($request->user(), $account);
        if ($allowed !== null) {
            $allowed = $account->branches()->where('is_active', true)->whereIn('id', $allowed)->pluck('id')->all();
        }

        return response()->json($this->reports->build($account->id, $branch?->id, $v['machine_id'] ?? null, $v['period_start'], $v['period_end'], $v['category'] ?? null, $v['status'] ?? null, $allowed));
    }
}
