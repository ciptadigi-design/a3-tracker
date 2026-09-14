<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\EffectiveCapabilityResolver;
use App\Services\GovernanceAudit;
use App\Services\PlatformPrivilegeService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class GovernanceAuditController extends Controller
{
    public function index(Request $request, string $id)
    {
        $account = Account::findOrFail($id);
        abort_if(app(PlatformPrivilegeService::class)->isSuperuser($request->user()), 403);
        app(EffectiveCapabilityResolver::class)->authorize($request->user(), $account, 'audit.view');

        return $this->history($request, $account);
    }

    public function platform(Request $request, string $id)
    {
        Gate::authorize('platform.manage');

        return $this->history($request, Account::findOrFail($id));
    }

    private function history(Request $request, Account $account)
    {
        $d = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:50', 'action' => 'sometimes|string|max:80']);
        $page = DB::table('governance_audit_logs')->where('account_id', $account->id)
            ->when($d['action'] ?? null, fn ($query, $action) => $query->where('action', $action))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($d['per_page'] ?? 20);
        $page->through(function ($row) {
            $metadata = json_decode($row->metadata ?? '{}', true) ?: [];
            // Legacy rows have no reliable actor-origin snapshot. Never infer history
            // from today's privileges or expose raw historical metadata.
            $type = in_array($metadata['actor_type'] ?? '', ['platform', 'tenant', 'system'], true) ? $metadata['actor_type'] : 'unknown';
            $changes = [];
            foreach (($metadata['version'] ?? null) === 1 ? ($metadata['changes'] ?? []) : [] as $key => $value) {
                if (in_array($key, GovernanceAudit::OMITTED_FIELDS, true)) {
                    $changes[$key] = ['before' => '[not retained]', 'after' => '[not retained]'];
                } elseif (in_array($key, GovernanceAudit::FIELDS, true) && is_array($value)) {
                    $changes[$key] = array_intersect_key($value, array_flip(['before', 'after']));
                }
            }

            return ['id' => $row->id, 'account_id' => $row->account_id, 'action' => $row->action,
                'actor' => ['user_id' => $row->actor_user_id, 'type' => $type, 'label' => match ($type) {
                    'platform' => 'Platform staff', 'tenant' => 'Account member', 'system' => 'System', default => 'Legacy actor'
                }],
                'target' => ['type' => $row->target_type, 'id' => $row->target_id],
                'changes' => app(GovernanceAudit::class)->sanitize($changes), 'created_at' => Carbon::parse($row->created_at, config('app.timezone'))->toIso8601String()];
        });

        return response()->json(['data' => $page]);
    }
}
