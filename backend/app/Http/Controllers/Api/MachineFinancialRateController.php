<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Models\MachineFinancialRate;
use App\Services\EffectiveCapabilityResolver;
use App\Services\GovernanceAudit;
use App\Services\MachineAccessResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Configured hourly estimates behind Owner "Dampak Finansial". Whole Rupiah; null = not configured. */
class MachineFinancialRateController extends Controller
{
    public function __construct(private MachineAccessResolver $access) {}

    public function show(Request $r, Machine $machine)
    {
        abort_unless($this->access->canAccess($r->user(), $machine), 403);
        app(EffectiveCapabilityResolver::class)->authorize($r->user(), $machine->account, 'machine_cost.view');

        return response()->json(['data' => $this->payload($machine->financialRate)]);
    }

    public function update(Request $r, Machine $machine)
    {
        abort_unless($this->access->canAccess($r->user(), $machine), 403);
        app(EffectiveCapabilityResolver::class)->authorize($r->user(), $machine->account, 'machine_cost.operating_cost.manage');
        $d = $r->validate([
            'operating_cost_per_hour' => 'present|nullable|integer|min:0|max:1000000000000',
            'estimated_revenue_per_hour' => 'present|nullable|integer|min:0|max:1000000000000',
        ]);

        return DB::transaction(function () use ($r, $machine, $d) {
            $rate = MachineFinancialRate::where('machine_id', $machine->id)->lockForUpdate()->first();
            $audit = app(GovernanceAudit::class);
            $before = $rate ? $audit->snapshot($rate) : [];
            $rate ??= new MachineFinancialRate(['account_id' => $machine->account_id, 'machine_id' => $machine->id]);
            $rate->fill($d + ['updated_by' => $r->user()->id])->save();
            $audit->changed($r->user(), 'machine_financial_rate.updated', 'machine_financial_rate', $rate->id, $machine->account_id, $before, $audit->snapshot($rate), array_keys($d));

            return response()->json(['data' => $this->payload($rate)]);
        });
    }

    private function payload(?MachineFinancialRate $rate): array
    {
        return ['operating_cost_per_hour' => $rate?->operating_cost_per_hour, 'estimated_revenue_per_hour' => $rate?->estimated_revenue_per_hour];
    }
}
