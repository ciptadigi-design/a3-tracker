<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ProvisionMember
{
    public function __construct(private GovernanceAudit $audit, private MemberLifecycle $lifecycle) {}

    public function execute(User $actor, Account $account, array $data): AccountMembership
    {
        abort_unless(app(AccountAccessResolver::class)->canGovern($actor, $account->fresh()), 403);

        return DB::transaction(function () use ($actor, $account, $data) {
            $account = Account::whereKey($account->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(AccountAccessResolver::class)->canGovern($actor, $account), 403);
            $email = IdentityInput::normalize($data['email']);
            $username = IdentityInput::normalize($data['username']);
            $ids = $this->lifecycle->validateBranches($account, $data['branch_ids'] ?? []);
            if (! in_array($data['role'], ['admin', 'technician', 'operator'], true) || ! $ids) {
                throw ValidationException::withMessages(['branch_ids' => 'At least one active branch and a non-owner role are required.']);
            }
            $byEmail = User::whereRaw('lower(email) = ?', [$email])->first();
            $byUsername = User::whereRaw('lower(username) = ?', [$username])->first();
            if ($byEmail || $byUsername) {
                // Only an exact same-account resource retry is accepted. No global
                // identity writes, no Owner demotion, no role/branch rewriting.
                $m = $byEmail && $byUsername && $byEmail->id === $byUsername->id
                    ? $account->memberships()->where('user_id', $byEmail->id)->first() : null;
                $assigned = $m?->branchAssignments()->where('is_active', true)->pluck('branch_id')->all() ?? [];
                sort($assigned);
                $requested = $ids;
                sort($requested);
                if (! $m || $byEmail->platformPrivilege()->exists() || ! $byEmail->isActive()
                    || $m->role !== $data['role'] || $assigned !== $requested
                    || ! in_array($m->status, ['active', 'suspended'], true)
                    || IdentityInput::normalize($byEmail->email) !== $email
                    || IdentityInput::normalize($byEmail->username ?? '') !== $username) {
                    throw new ConflictHttpException('IDENTITY_CONFLICT');
                }
                if ($m->status === 'suspended') {
                    $m->update(['status' => 'active']);
                }

                return $m->load('user', 'branchAssignments');
            }
            $user = User::create(['name' => trim($data['name']), 'email' => $email, 'username' => $username, 'password' => $data['password'], 'status' => 'active']);
            $m = $account->memberships()->create(['user_id' => $user->id, 'role' => $data['role'], 'status' => 'active', 'accepted_at' => now()]);
            $this->lifecycle->assign($m, $ids);
            $this->audit->record($actor, 'member.provisioned', 'account_membership', $m->id, $account->id, ['role' => $m->role]);

            return $m->load('user', 'branchAssignments');
        });
    }
}
