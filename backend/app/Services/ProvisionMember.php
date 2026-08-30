<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\AccountMembershipBranch;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ProvisionMember
{
    public function __construct(private GovernanceAudit $audit) {}

    public function execute(User $actor, Account $account, array $data): AccountMembership
    {
        return DB::transaction(function () use ($actor, $account, $data) {
            $email = strtolower(trim($data['email']));
            $username = strtolower(trim($data['username']));
            $ids = array_values(array_unique($data['branch_ids'] ?? []));
            if ($data['role'] === 'owner' || count($ids) === 0 || Branch::where('account_id', $account->id)->where('is_active', true)->whereIn('id', $ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(['branch_ids' => 'At least one active branch in this account is required.']);
            } $byEmail = User::whereRaw('lower(email)=?', [$email])->first();
            $byUsername = User::whereRaw('lower(username)=?', [$username])->first();
            if ($byEmail && $byUsername && $byEmail->id !== $byUsername->id) {
                throw ValidationException::withMessages(['email' => 'Email and username belong to different users.']);
            } $user = $byEmail ?? $byUsername;
            if (! $user) {
                $user = User::create(['name' => trim($data['name']), 'email' => $email, 'username' => $username, 'password' => Hash::make($data['password']), 'status' => 'active']);
            } $m = AccountMembership::updateOrCreate(['account_id' => $account->id, 'user_id' => $user->id], ['role' => $data['role'], 'status' => 'active', 'accepted_at' => now()]);
            AccountMembershipBranch::where('membership_id', $m->id)->update(['is_active' => false]);
            foreach ($ids as $id) {
                AccountMembershipBranch::updateOrCreate(['membership_id' => $m->id, 'branch_id' => $id], ['account_id' => $account->id, 'is_active' => true]);
            } $this->audit->record($actor, 'member.provisioned', 'account_membership', $m->id, $account->id, ['role' => $m->role]);

            return $m->load('user', 'branchAssignments');
        });
    }
}
