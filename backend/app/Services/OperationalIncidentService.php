<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Branch;
use App\Models\Machine;
use App\Models\OperationalIncident;
use App\Models\OperationalPerson;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationalIncidentService
{
    public function __construct(private BranchAccessResolver $branches, private OperationalPersonEligibilityService $people) {}

    /**
     * Canonical Incident/Error Operational Person population: every active
     * person with an active assignment to this branch. Deliberately broader
     * than the counter-operator population (OperationalPersonEligibilityService) —
     * an incident's "PIC Terlibat" (responsible person) may be staff who are
     * not counter operators. The frontend derives the stricter Operator
     * subset (can_record_counter=true) from this same list itself; it must
     * not be pre-filtered here. Matches the pre-Laravel-port Supabase
     * reference query exactly (operational_people joined to
     * operational_person_branches, account + active + active branch
     * assignment only — see src/services/supabase/operationalIncidents.js).
     */
    public function eligiblePeopleForBranch(Branch $branch)
    {
        return OperationalPerson::where('account_id', $branch->account_id)->where('is_active', true)
            ->with(['branchAssignments' => fn ($q) => $q->where('branch_id', $branch->id)->where('is_active', true)->with('branch')])
            ->whereHas('branchAssignments', fn ($q) => $q->where('branch_id', $branch->id)->where('is_active', true))
            ->orderBy('name')->get();
    }

    /**
     * Validates operator_person_id/responsible_person_id against their
     * distinct eligibility rules (operator = strict counter-operator
     * population, matching Daily/Inventory/Replacement; responsible = the
     * broader active branch population) and refreshes the paired
     * *_name_snapshot fields to match. A client cannot bypass this by
     * submitting an arbitrary id — every write path (create and update)
     * must call this rather than trusting the submitted id/snapshot pair.
     * When a field is cleared, its snapshot is cleared too so the two never
     * drift out of sync.
     */
    public function resolvePersonSnapshots(Branch $branch, array $v): array
    {
        foreach (['operator_person_id' => 'operator_name_snapshot', 'responsible_person_id' => 'responsible_name_snapshot'] as $field => $snapshotField) {
            if (! empty($v[$field])) {
                $p = $field === 'operator_person_id' ? $this->people->eligibleForBranch($branch, $v[$field], true) : $this->people->eligibleForBranch($branch, $v[$field]);
                if (! $p) {
                    throw ValidationException::withMessages([$field => 'Operational person is not eligible for this branch.']);
                }
                $v[$snapshotField] = $p->name;
            } else {
                $v[$field] = null;
                $v[$snapshotField] = null;
            }
        }

        return $v;
    }

    public function create($user, Account $account, Branch $branch, array $v): OperationalIncident
    {
        $branch = $branch->fresh();
        $membership = AccountMembership::where('account_id', $account->id)->where('user_id', $user->id)->where('status', 'active')->first();
        $branchScope = $membership && ($membership->role === 'owner' || AccountMembershipBranch::where(['account_id' => $account->id, 'membership_id' => $membership->id, 'branch_id' => $branch->id, 'is_active' => true])->exists());
        // scope is intentionally checked against persisted tenant rows
        if (! $branchScope || ! $branch->is_active || (string) $branch->account_id !== (string) $account->id) {
            throw ValidationException::withMessages(['scope' => 'Invalid account or branch scope.']);
        }
        if (! empty($v['machine_id'])) {
            $machine = Machine::where('id', $v['machine_id'])->where('account_id', $account->id)->where('branch_id', $branch->id)->where('status', 'active')->first();
            if (! $machine) {
                throw ValidationException::withMessages(['machine_id' => 'Machine is not in this branch.']);
            }
        } else {
            $machine = null;
        }
        $v = $this->resolvePersonSnapshots($branch, $v);
        $existing = OperationalIncident::where('account_id', $account->id)->where('client_request_id', $v['client_request_id'])->first();
        if ($existing) {
            return $existing;
        }
        $material = (string) ($v['material_loss'] ?? 0);
        $service = (string) ($v['service_loss'] ?? 0);
        $mult = (string) ($v['penalty_multiplier'] ?? 1);
        $assessed = function_exists('bcmul') ? bcadd(bcmul(bcadd($material, $service, 2), $mult, 2), '0', 2) : number_format(((float) $material + (float) $service) * (float) $mult, 2, '.', '');

        return DB::transaction(fn () => OperationalIncident::create(array_merge($v, ['account_id' => $account->id, 'branch_id' => $branch->id, 'assessed_loss' => $assessed, 'created_by' => $user->id, 'updated_by' => $user->id, 'status' => 'open'])));
    }

    public function effectiveLoss(OperationalIncident $i): string
    {
        if ($i->assessed_loss !== null) {
            return (string) $i->assessed_loss;
        }
        $base = $i->base_amount !== null ? (float) $i->base_amount : (float) $i->material_loss + (float) $i->service_loss;

        return number_format($base * (float) ($i->penalty_multiplier ?: 1), 2, '.', '');
    }

    public function canMutate($user, OperationalIncident $incident): bool
    {
        $account = Account::find($incident->account_id);

        return $account && app(AccountAccessResolver::class)->canManageOperational($user, $account);
    }
}
