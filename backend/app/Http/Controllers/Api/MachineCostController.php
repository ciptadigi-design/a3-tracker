<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Services\EffectiveCapabilityResolver;
use App\Services\MachineAccessResolver;
use App\Services\MachineCostService;
use App\Services\OperationalPeriodRange;
use App\Services\OperationalPersonEligibilityService;
use App\Services\OverviewPurchaseSummaryService;
use App\Services\ReplayFields;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class MachineCostController extends Controller
{
    public function __construct(private MachineCostService $service, private MachineAccessResolver $access, private OverviewPurchaseSummaryService $purchases) {}

    public function show(Request $r, Machine $machine)
    {
        $v = $r->validate($r->boolean('summary_only') ? OperationalPeriodRange::rules($r) : ['period_start' => 'required|date_format:Y-m-d', 'period_end' => 'required|date_format:Y-m-d']);
        abort_unless($this->access->canAccess($r->user(), $machine), 403);

        app(EffectiveCapabilityResolver::class)->authorize($r->user(), $machine->account, 'machine_cost.view');
        $result = $this->service->period($machine, $v['period_start'], $v['period_end'], ! $r->boolean('summary_only'));
        if ($r->boolean('summary_only')) {
            $result['purchase_summary'] = $this->purchases->forBranchPeriod(
                $machine->account_id,
                $machine->branch_id,
                $v['period_start'],
                $v['period_end'],
            );

            return response()->json($result);
        }
        $result['operating_costs'] = DB::table('machine_operating_costs')->where('machine_id', $machine->id)->orderByDesc('created_at')->get();
        $result['selling_prices'] = DB::table('machine_selling_prices')->where('machine_id', $machine->id)->orderByDesc('effective_from')->get();

        return response()->json($result);
    }

    public function createSellingPrice(Request $r, Machine $machine)
    {
        abort_unless($this->access->canAccess($r->user(), $machine, true), 403);
        app(EffectiveCapabilityResolver::class)->authorize($r->user(), $machine->account, 'machine_cost.selling_price.manage');
        $d = $r->validate(['price_per_click' => 'required|numeric|gt:0', 'effective_from' => 'required|date', 'notes' => 'nullable|string', 'client_request_id' => 'required|uuid']);

        return DB::transaction(function () use ($r, $machine, $d) {
            $existing = DB::table('machine_selling_prices')->where('account_id', $machine->account_id)->where('client_request_id', $d['client_request_id'])->lockForUpdate()->first();
            if ($existing) {
                ReplayFields::match($existing, ['notes' => $d['notes'] ?? null]);
                $same = (string) $existing->machine_id === (string) $machine->id
                    && (float) $existing->price_per_click === (float) $d['price_per_click']
                    && Carbon::parse($existing->effective_from)->eq(Carbon::parse($d['effective_from']));
                if (! $same) {
                    throw new ConflictHttpException('client request id was already used for a different selling price');
                }

                return response()->json(['data' => $existing]);
            }

            $id = (string) Str::uuid();
            DB::table('machine_selling_prices')->insert([
                'id' => $id,
                'account_id' => $machine->account_id,
                'machine_id' => $machine->id,
                'price_per_click' => $d['price_per_click'],
                'effective_from' => Carbon::parse($d['effective_from'])->utc(),
                'notes' => $d['notes'] ?? null,
                'client_request_id' => $d['client_request_id'],
                'created_by' => $r->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json(['data' => DB::table('machine_selling_prices')->find($id)], 201);
        });
    }

    public function voidSellingPrice(Request $r, string $price)
    {
        $d = $r->validate(['reason' => 'required|string', 'client_request_id' => 'required|uuid']);
        $row = DB::table('machine_selling_prices')->find($price);
        abort_unless($row, 404);
        abort_unless($this->access->canAccess($r->user(), Machine::find($row->machine_id), true), 403);
        app(EffectiveCapabilityResolver::class)->authorize($r->user(), Machine::findOrFail($row->machine_id)->account, 'machine_cost.selling_price.manage');
        if ($row->status === 'voided') {
            throw new ConflictHttpException('this selling price is already voided');
        }
        DB::table('machine_selling_prices')->where('id', $price)->update(['status' => 'voided', 'voided_at' => now(), 'voided_by' => $r->user()->id, 'void_reason' => $d['reason'], 'updated_at' => now()]);

        return response()->json(['data' => DB::table('machine_selling_prices')->find($price)]);
    }

    public function createOperatingCost(Request $r, Machine $machine)
    {
        abort_unless($this->access->canAccess($r->user(), $machine, true), 403);
        app(EffectiveCapabilityResolver::class)->authorize($r->user(), $machine->account, 'machine_cost.operating_cost.manage');
        $d = $r->validate(['category' => 'required|string', 'amount' => 'required|numeric|gt:0', 'allocation_method' => 'required|string', 'description' => 'required|string', 'effective_at' => 'nullable|date', 'period_start' => 'nullable|date', 'period_end' => 'nullable|date', 'operational_person_id' => 'nullable|uuid', 'external_reference' => 'nullable|string', 'notes' => 'nullable|string', 'client_request_id' => 'required|uuid']);
        if (! empty($d['operational_person_id'])) {
            if (! app(OperationalPersonEligibilityService::class)->eligibleForBranch($machine->branch, $d['operational_person_id'])) {
                throw ValidationException::withMessages(['operational_person_id' => 'Person is not available in this branch.']);
            }
        }
        $d['effective_at'] = ! empty($d['effective_at']) ? Carbon::parse($d['effective_at'])->utc() : null;
        $d['period_start'] = ! empty($d['period_start']) ? Carbon::parse($d['period_start'])->toDateString() : null;
        $d['period_end'] = ! empty($d['period_end']) ? Carbon::parse($d['period_end'])->toDateString() : null;
        $id = (string) Str::uuid();
        DB::table('machine_operating_costs')->insert(['id' => $id, 'account_id' => $machine->account_id, 'machine_id' => $machine->id, 'source_type' => 'manual', 'status' => 'posted'] + $d + ['created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => DB::table('machine_operating_costs')->find($id)], 201);
    }

    public function voidOperatingCost(Request $r, string $cost)
    {
        $d = $r->validate(['reason' => 'required|string', 'client_request_id' => 'required|uuid']);
        $row = DB::table('machine_operating_costs')->find($cost);
        abort_unless($row && $this->access->canAccess($r->user(), Machine::find($row->machine_id), true), 403);
        app(EffectiveCapabilityResolver::class)->authorize($r->user(), Machine::findOrFail($row->machine_id)->account, 'machine_cost.operating_cost.manage');
        DB::table('machine_operating_costs')->where('id', $cost)->update(['status' => 'voided', 'voided_at' => now(), 'voided_by' => $r->user()->id, 'void_reason' => $d['reason'], 'updated_at' => now()]);

        return response()->json(['data' => DB::table('machine_operating_costs')->find($cost)]);
    }
}
