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

class ReportsController extends Controller
{
    public function __construct(private OperationalReportService $reports, private AccountAccessResolver $accounts, private BranchAccessResolver $branches) {}

    public function __invoke(Request $request)
    {
        $v = $request->validate([
            'account_id' => 'required|uuid', 'branch_id' => 'nullable|uuid', 'machine_id' => 'nullable|uuid',
            'period_start' => 'required|date_format:Y-m-d', 'period_end' => 'required|date_format:Y-m-d',
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
