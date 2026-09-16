<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Coarse action authority only. Callers must independently authorize persisted resources. */
class EffectiveCapabilityResolver
{
    public const POLICY_ACTIONS = [
        'operator_can_initialize_component' => ['components.lifecycle.initialize'],
        'operator_can_replace_component' => ['components.replace.inventory', 'components.replace.external'],
        'operator_can_create_purchase' => ['inventory.purchase.create'],
        'operator_can_receive_goods' => ['inventory.receive'],
        'operator_can_adjust_inventory' => ['inventory.adjust'],
        'operator_can_transfer_inventory' => ['inventory.transfer'],
        'operator_can_log_errors' => ['incidents.create'],
        'operator_can_create_maintenance_ticket' => ['maintenance.ticket.create'],
    ];

    private const GOVERNANCE = ['account.manage', 'members.manage', 'branches.manage', 'settings.policy.manage', 'audit.view'];

    private const MANAGEMENT = ['settings.view', 'machines.manage', 'catalog.manage', 'operational_people.manage', 'suppliers.manage', 'inventory.items.manage', 'inventory.locations.manage', 'inventory.opening', 'components.configure', 'counters.correct', 'incidents.manage', 'click_targets.manage', 'machine_cost.selling_price.manage', 'machine_cost.operating_cost.manage', 'maintenance.ticket.assign', 'maintenance.ticket.update', 'maintenance.knowledge.submit', 'maintenance.knowledge.review'];

    private const READ_OPERATIONAL = ['counters.record', 'reports.view', 'machine_cost.view', 'maintenance.view'];

    public function policy(Account $account): array
    {
        $row = (array) DB::table('account_operational_permissions')->where('account_id', $account->id)->first();

        return array_map(fn ($key) => (bool) ($row[$key] ?? false), array_combine(array_keys(self::POLICY_ACTIONS), array_keys(self::POLICY_ACTIONS)));
    }

    /** Also used for the read-only Settings role matrix, so it cannot invent role rules. */
    public function forRole(?string $role, array $policy, bool $platform = false): array
    {
        $operational = array_merge(...array_values(self::POLICY_ACTIONS));
        $result = array_fill_keys(array_merge(['catalog.global.manage'], self::GOVERNANCE, self::MANAGEMENT, self::READ_OPERATIONAL, $operational), false);
        if (! $platform && ! in_array($role, ['owner', 'admin', 'technician', 'operator'], true)) {
            return $result;
        }
        $result['catalog.global.manage'] = $platform;
        foreach (self::READ_OPERATIONAL as $key) {
            $result[$key] = true;
        }
        if ($platform || $role === 'owner') {
            foreach (self::GOVERNANCE as $key) {
                $result[$key] = true;
            }
        }
        if ($platform || in_array($role, ['owner', 'admin'], true)) {
            foreach (array_merge(self::MANAGEMENT, $operational) as $key) {
                $result[$key] = true;
            }
        } elseif ($role === 'technician') {
            foreach (['components.replace.inventory', 'components.replace.external', 'incidents.create', 'maintenance.ticket.create', 'maintenance.ticket.update', 'maintenance.action.create', 'maintenance.knowledge.submit'] as $key) {
                $result[$key] = true;
            }
        } elseif ($role === 'operator') {
            foreach (self::POLICY_ACTIONS as $policyKey => $actions) {
                foreach ($actions as $key) {
                    $result[$key] = ($policy[$policyKey] ?? false) === true;
                }
            }
        }

        return $result;
    }

    public function resolve(User $user, Account $account): array
    {
        $account = $account->fresh();
        $user = $user->fresh();
        if (! $user || ! $account || ! $user->isActive() || $account->status !== 'active') {
            return $this->forRole(null, []);
        }
        $membership = AccountMembership::where('account_id', $account->id)->where('user_id', $user->id)->first();
        // A suspended/revoked tenant membership is never made actionable by its old role.
        if ($membership && $membership->status !== 'active') {
            return $this->forRole(null, []);
        }

        return $this->forRole($membership?->role, $this->policy($account), app(PlatformPrivilegeService::class)->isSuperuser($user));
    }

    public function allows(User $user, Account $account, string $capability): bool
    {
        return $this->resolve($user, $account)[$capability] ?? false;
    }

    public function authorize(User $user, Account $account, string $capability): void
    {
        abort_unless($this->allows($user, $account, $capability), 403);
    }
}
