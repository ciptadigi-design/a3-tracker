<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Services\MachineAccessResolver;
use App\Services\MachineCostService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MachineCostController extends Controller
{
    public function __construct(private MachineCostService $service, private MachineAccessResolver $access) {}

    public function show(Request $r, Machine $machine)
    {
        $v = $r->validate(['period_start' => 'required|date_format:Y-m-d', 'period_end' => 'required|date_format:Y-m-d']);
        abort_unless($this->access->canAccess($r->user(), $machine), 403);

        $result = $this->service->period($machine, $v['period_start'], $v['period_end']);
        $result['operating_costs'] = DB::table('machine_operating_costs')->where('machine_id', $machine->id)->orderByDesc('created_at')->get();
        $result['selling_prices'] = DB::table('machine_selling_prices')->where('machine_id', $machine->id)->orderByDesc('effective_from')->get();

        return response()->json($result);
    }

    public function createSellingPrice(Request $r, Machine $machine)
    {
        abort_unless($this->access->canAccess($r->user(), $machine, true), 403);
        $d = $r->validate(['price_per_click' => 'required|numeric|gt:0', 'effective_from' => 'required|date', 'notes' => 'nullable|string', 'client_request_id' => 'required|uuid']);
        $id = (string) Str::uuid();
        DB::table('machine_selling_prices')->insert(['id' => $id, 'account_id' => $machine->account_id, 'machine_id' => $machine->id] + $d + ['created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => DB::table('machine_selling_prices')->find($id)], 201);
    }

    public function voidSellingPrice(Request $r, string $price)
    {
        $d = $r->validate(['reason' => 'required|string', 'client_request_id' => 'required|uuid']);
        $row = DB::table('machine_selling_prices')->find($price);
        abort_unless($row && $this->access->canAccess($r->user(), Machine::find($row->machine_id), true), 403);
        DB::table('machine_selling_prices')->where('id', $price)->update(['status' => 'voided', 'voided_at' => now(), 'voided_by' => $r->user()->id, 'void_reason' => $d['reason'], 'updated_at' => now()]);

        return response()->json(['data' => DB::table('machine_selling_prices')->find($price)]);
    }

    public function createOperatingCost(Request $r, Machine $machine)
    {
        abort_unless($this->access->canAccess($r->user(), $machine, true), 403);
        $d = $r->validate(['category' => 'required|string', 'amount' => 'required|numeric|gt:0', 'allocation_method' => 'required|string', 'description' => 'required|string', 'effective_at' => 'nullable|date', 'period_start' => 'nullable|date', 'period_end' => 'nullable|date', 'operational_person_id' => 'nullable|uuid', 'external_reference' => 'nullable|string', 'notes' => 'nullable|string', 'client_request_id' => 'required|uuid']);
        $id = (string) Str::uuid();
        DB::table('machine_operating_costs')->insert(['id' => $id, 'account_id' => $machine->account_id, 'machine_id' => $machine->id, 'source_type' => 'manual', 'status' => 'posted'] + $d + ['created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => DB::table('machine_operating_costs')->find($id)], 201);
    }

    public function voidOperatingCost(Request $r, string $cost)
    {
        $d = $r->validate(['reason' => 'required|string', 'client_request_id' => 'required|uuid']);
        $row = DB::table('machine_operating_costs')->find($cost);
        abort_unless($row && $this->access->canAccess($r->user(), Machine::find($row->machine_id), true), 403);
        DB::table('machine_operating_costs')->where('id', $cost)->update(['status' => 'voided', 'voided_at' => now(), 'voided_by' => $r->user()->id, 'void_reason' => $d['reason'], 'updated_at' => now()]);

        return response()->json(['data' => DB::table('machine_operating_costs')->find($cost)]);
    }
}
