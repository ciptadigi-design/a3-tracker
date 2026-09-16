<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\MachineErrorCode;
use App\Models\MachineModel;
use App\Models\MaintenanceKnowledge;
use App\Models\MaintenanceTicket;
use App\Services\EffectiveCapabilityResolver;
use App\Services\GovernanceAudit;
use App\Services\ScopedReference;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class MaintenanceKnowledgeController extends Controller
{
    private const REVIEW_TRANSITIONS = [
        'DRAFT' => ['REVIEW'],
        'REVIEW' => ['PUBLISHED', 'DRAFT'],
        'PUBLISHED' => [],
    ];

    public function index(Request $r)
    {
        $ids = $r->user()->memberships()->where('status', 'active')->pluck('account_id');
        // Internal knowledge is always tenant-owned (see the migration note on
        // maintenance_knowledge.account_id) - no global/platform rows exist to union in.
        $q = MaintenanceKnowledge::whereIn('account_id', $ids);
        // Default view is the published knowledge base (what Operators browse); an
        // explicit approval_status filter lets Technicians/Admins see drafts/pending review.
        $q->where('approval_status', $r->filled('approval_status') ? $r->string('approval_status') : 'PUBLISHED');
        if ($r->filled('machine_model_id')) {
            $q->where('machine_model_id', $r->string('machine_model_id'));
        }
        if ($r->filled('error_code_id')) {
            $q->where('error_code_id', $r->string('error_code_id'));
        }

        return response()->json(['data' => $q->orderByDesc('created_at')->get()]);
    }

    public function store(Request $r)
    {
        $d = $r->validate([
            'account_id' => 'required|uuid',
            'machine_model_id' => 'nullable|uuid',
            'error_code_id' => 'nullable|uuid',
            'source_ticket_id' => 'nullable|uuid',
            'problem' => 'required|string',
            'symptoms' => 'nullable|string',
            'solution' => 'required|string',
            'success_notes' => 'nullable|string',
        ]);
        $account = Account::findOrFail($d['account_id']);
        app(EffectiveCapabilityResolver::class)->authorize($r->user(), $account, 'maintenance.knowledge.submit');
        ScopedReference::activeGlobalOrOwned(MachineModel::class, $d['machine_model_id'] ?? null, $account->id, 'machine_model_id');
        if (! empty($d['error_code_id'])) {
            $code = MachineErrorCode::findOrFail($d['error_code_id']);
            abort_unless($code->account_id === null || $code->account_id === $account->id, 422);
        }
        if (! empty($d['source_ticket_id'])) {
            // Resolved from the account's own scope, not trusted as a bare id -
            // a ticket belonging to another account 404s instead of linking cross-tenant.
            MaintenanceTicket::where('account_id', $account->id)->findOrFail($d['source_ticket_id']);
        }

        $entry = MaintenanceKnowledge::create($d + ['submitted_by' => $r->user()->id, 'submitted_at' => now(), 'approval_status' => 'DRAFT']);

        app(GovernanceAudit::class)->changed($r->user(), 'maintenance_knowledge.submitted', 'maintenance_knowledge', $entry->id, $account->id, [], app(GovernanceAudit::class)->snapshot($entry));

        return response()->json(['data' => $entry], 201);
    }

    public function review(Request $r, string $id)
    {
        $entry = MaintenanceKnowledge::findOrFail($id);
        app(EffectiveCapabilityResolver::class)->authorize($r->user(), Account::findOrFail($entry->account_id), 'maintenance.knowledge.review');
        $d = $r->validate(['approval_status' => 'required|in:REVIEW,PUBLISHED,DRAFT']);
        $allowed = self::REVIEW_TRANSITIONS[$entry->approval_status] ?? [];
        if (! in_array($d['approval_status'], $allowed, true)) {
            throw new ConflictHttpException("cannot transition maintenance knowledge from {$entry->approval_status} to {$d['approval_status']}");
        }
        $before = app(GovernanceAudit::class)->snapshot($entry);
        $updates = ['approval_status' => $d['approval_status'], 'reviewed_by' => $r->user()->id, 'reviewed_at' => now()];
        if ($d['approval_status'] === 'PUBLISHED') {
            $updates['published_at'] = now();
        }
        $entry->update($updates);

        app(GovernanceAudit::class)->changed($r->user(), 'maintenance_knowledge.review_status_changed', 'maintenance_knowledge', $entry->id, $entry->account_id, $before, app(GovernanceAudit::class)->snapshot($entry), array_keys($entry->getChanges()));

        return response()->json(['data' => $entry]);
    }
}
