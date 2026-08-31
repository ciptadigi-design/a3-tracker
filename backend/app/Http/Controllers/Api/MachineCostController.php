<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Services\MachineAccessResolver;
use App\Services\MachineCostService;
use Illuminate\Http\Request;

class MachineCostController extends Controller
{
    public function __construct(private MachineCostService $service, private MachineAccessResolver $access) {}

    public function show(Request $r, Machine $machine)
    {
        $v = $r->validate(['period_start' => 'required|date_format:Y-m-d', 'period_end' => 'required|date_format:Y-m-d']);
        abort_unless($this->access->canAccess($r->user(), $machine), 403);

        return response()->json($this->service->period($machine, $v['period_start'], $v['period_end']));
    }
}
