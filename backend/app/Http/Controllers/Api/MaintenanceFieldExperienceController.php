<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Machine;
use App\Services\AccountAccessResolver;
use App\Services\EffectiveCapabilityResolver;
use App\Services\MaintenanceFieldExperienceSearch;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Pengalaman Lapangan - read-only, account-scoped search and summary of completed repairs.
 *
 * Authorization is independent of ticket permissions on purpose: any active member of the
 * selected account may read the safe projection, across all of that account's branches, but
 * the projection never grants the ticket itself (can_open mirrors the ticket-detail rule) and
 * there is no write route. A non-member gets 403 whether the account exists or not, and a
 * ticket id outside the selected account or outside eligibility is a uniform 404.
 */
class MaintenanceFieldExperienceController extends Controller
{
    public function __construct(private MaintenanceFieldExperienceSearch $search) {}

    public function index(Request $r)
    {
        $d = $r->validate([
            'account_id' => 'required|uuid',
            'q' => ['required', 'string', 'min:2', 'max:100', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            'machine_id' => 'nullable|uuid',
            'machine_model_id' => 'nullable|uuid',
            'page' => 'nullable|integer|min:1|max:1000',
            'per_page' => 'nullable|integer|min:1|max:25',
        ]);
        $account = $this->authorizeAccount($r, $d['account_id']);

        $query = MaintenanceFieldExperienceSearch::interpret($d['q']);
        if ($query['kind'] === 'text' && $query['tokens'] === []) {
            throw ValidationException::withMessages(['q' => 'Kata kunci terlalu pendek.']);
        }

        // Machine context only ranks results; it must belong to the selected account.
        $modelId = $d['machine_model_id'] ?? null;
        $machineId = null;
        if (! empty($d['machine_id'])) {
            $machine = Machine::where('account_id', $account->id)->find($d['machine_id']);
            if (! $machine) {
                throw ValidationException::withMessages(['machine_id' => 'Mesin tidak ditemukan pada akun ini.']);
            }
            $machineId = $machine->id;
            $modelId = $machine->machine_model_id;
        }

        $result = $this->search->search($r->user(), $account->id, $query, $machineId, $modelId, (int) ($d['per_page'] ?? 10), (int) ($d['page'] ?? 1));

        return response()->json($result + ['query' => ['kind' => $query['kind'], 'codes' => $query['codes']]]);
    }

    public function show(Request $r, string $ticket)
    {
        $d = $r->validate(['account_id' => 'required|uuid']);
        $account = $this->authorizeAccount($r, $d['account_id']);

        $data = $this->search->find($r->user(), $account->id, $ticket);
        abort_if($data === null, 404);

        return response()->json(['data' => $data]);
    }

    private function authorizeAccount(Request $r, string $accountId): Account
    {
        $account = Account::find($accountId);
        abort_unless(
            $account
            && app(AccountAccessResolver::class)->membership($r->user(), $account) !== null
            && app(EffectiveCapabilityResolver::class)->allows($r->user(), $account, 'maintenance.view'),
            403,
        );

        return $account;
    }
}
