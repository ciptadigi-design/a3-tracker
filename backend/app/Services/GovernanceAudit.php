<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GovernanceAudit
{
    public function record(?User $actor, string $action, string $type, ?string $targetId, ?string $accountId = null, array $metadata = []): void
    {
        DB::table('governance_audit_logs')->insert(['id' => (string) Str::uuid(), 'actor_user_id' => $actor?->id, 'action' => $action, 'target_type' => $type, 'target_id' => $targetId, 'account_id' => $accountId, 'metadata' => $metadata ? json_encode($metadata) : null, 'created_at' => now()]);
    }
}
