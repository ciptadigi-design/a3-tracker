<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Branch;
use App\Models\OperationalIncident;
use App\Services\BranchAccessResolver;
use App\Services\OperationalIncidentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IncidentsController extends Controller
{
    public function __construct(private OperationalIncidentService $service, private BranchAccessResolver $branches) {}

    public function index(Request $r, Account $account, Branch $branch)
    {
        abort_unless($branch->account_id === $account->id, 404);
        abort_unless($this->branches->canAccess($r->user(), $branch), 403);

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
        abort_unless($this->branches->canAccess($r->user(), $branch), 403);

        return response()->json(['incident' => $incident]);
    }

    public function update(Request $r, OperationalIncident $incident)
    {
        abort_unless($this->service->canMutate($r->user(), $incident), 403);
        abort_if($incident->status !== 'open', 409, 'Only open incidents can be edited.');
        $d = $r->validate(['occurred_at' => 'required|date', 'category' => 'required|string|max:40', 'incident_type' => 'required|string|max:40', 'description' => 'required|string', 'machine_id' => 'nullable|uuid', 'operator_person_id' => 'nullable|uuid', 'responsible_person_id' => 'nullable|uuid', 'invoice_number' => 'nullable|string', 'customer_name_snapshot' => 'nullable|string', 'product_name_snapshot' => 'nullable|string', 'qty_affected' => 'nullable|integer|min:1', 'material_loss' => 'nullable|numeric|min:0', 'service_loss' => 'nullable|numeric|min:0', 'cause' => 'nullable|string', 'prevention' => 'nullable|string', 'customer_resolution' => 'nullable|string', 'change_reason' => 'required|string|max:500']);
        $old = $incident->only(array_keys($d));
        $incident->fill(array_diff_key($d, ['change_reason' => true]));
        $incident->updated_by = $r->user()->id;
        $incident->assessed_loss = ((float) ($d['material_loss'] ?? 0) + (float) ($d['service_loss'] ?? 0)) * (float) ($incident->penalty_multiplier ?: 1);
        DB::transaction(function () use ($incident, $old, $d, $r) {
            $incident->save();
            DB::table('operational_incident_revisions')->insert(['id' => (string) Str::uuid(), 'account_id' => $incident->account_id, 'incident_id' => $incident->id, 'changed_by' => $r->user()->id, 'changed_at' => now(), 'change_reason' => $d['change_reason'], 'old_values' => json_encode($old), 'new_values' => json_encode($incident->only(array_keys($old))), 'changed_fields' => json_encode(array_keys(array_filter($old, fn ($v, $k) => $incident->{$k} != $v, ARRAY_FILTER_USE_BOTH))), 'created_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(['incident' => $incident->fresh()]);
    }

    public function solve(Request $r, OperationalIncident $incident)
    {
        abort_unless($this->service->canMutate($r->user(), $incident), 403);
        abort_if($incident->status !== 'open', 409, 'Incident is not open.');
        $d = $r->validate(['resolution_note' => 'nullable|string|max:1000']);
        $incident->update(['status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => $r->user()->id, 'resolution_note' => $d['resolution_note'] ?? null, 'updated_by' => $r->user()->id]);

        return response()->json(['incident' => $incident->fresh()]);
    }

    public function void(Request $r, OperationalIncident $incident)
    {
        abort_unless($this->service->canMutate($r->user(), $incident), 403);
        $d = $r->validate(['void_reason' => 'required|string|max:1000']);
        $incident->update(['status' => 'voided', 'voided_at' => now(), 'voided_by' => $r->user()->id, 'void_reason' => $d['void_reason'], 'updated_by' => $r->user()->id]);

        return response()->json(['incident' => $incident->fresh()]);
    }
}
