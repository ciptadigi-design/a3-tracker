<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Branch;
use App\Models\OperationalIncident;
use App\Services\OperationalIncidentService;
use Illuminate\Http\Request;

class IncidentsController extends Controller
{
    public function __construct(private OperationalIncidentService $service) {}

    public function index(Request $r, Account $account, Branch $branch)
    {
        abort_unless($branch->account_id === $account->id, 404);

        return response()->json(['incidents' => OperationalIncident::where('account_id', $account->id)->where('branch_id', $branch->id)->latest('occurred_at')->get()]);
    }

    public function store(Request $r, Account $account, Branch $branch)
    {
        $v = $r->validate(['client_request_id' => 'required|uuid', 'occurred_at' => 'required|date', 'category' => 'required|string|max:40', 'incident_type' => 'required|string|max:40', 'description' => 'required|string', 'machine_id' => 'nullable|uuid', 'operator_person_id' => 'nullable|uuid', 'responsible_person_id' => 'nullable|uuid', 'invoice_number' => 'nullable|string', 'customer_name_snapshot' => 'nullable|string', 'product_name_snapshot' => 'nullable|string', 'qty_affected' => 'nullable|integer|min:1', 'material_loss' => 'nullable|numeric|min:0', 'service_loss' => 'nullable|numeric|min:0', 'penalty_multiplier' => 'nullable|numeric|min:1', 'cause' => 'nullable|string', 'prevention' => 'nullable|string', 'customer_resolution' => 'nullable|string']);

        return response()->json(['incident' => $this->service->create($r->user(), $account, $branch, $v)], 201);
    }

    public function show(Request $r, Account $account, Branch $branch, OperationalIncident $incident)
    {
        abort_unless($incident->account_id === $account->id && $incident->branch_id === $branch->id, 404);

        return response()->json(['incident' => $incident]);
    }
}
