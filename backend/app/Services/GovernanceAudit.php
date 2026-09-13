<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GovernanceAudit
{
    // Allowlisted administrative fields only. Free text, contact details and credentials
    // are deliberately excluded; callers cannot turn a request payload into audit data.
    public const FIELDS = ['user_id', 'role', 'status', 'branch_ids', 'code', 'name', 'default_timezone', 'default_currency', 'machine_economics_advanced_enabled', 'is_active', 'branch_id', 'machine_id', 'machine_model_id', 'manufacturer_id', 'model_code', 'machine_code', 'display_name', 'timezone', 'component_id', 'profile_id', 'profile_slot_id', 'slot_code', 'slot_name', 'display_order', 'tracking_method', 'baseline_expected_clicks', 'healthy_threshold_percent', 'watch_threshold_percent', 'warning_threshold_percent', 'critical_threshold_percent', 'adaptive_enabled', 'source_type', 'person_id', 'can_record_counter', 'calendar_date', 'exception_type', 'excluded_from_target', 'cleared_at', 'operator_can_initialize_component', 'operator_can_replace_component', 'operator_can_create_purchase', 'operator_can_receive_goods', 'operator_can_adjust_inventory', 'operator_can_transfer_inventory', 'operator_can_log_errors'];

    public const OMITTED_FIELDS = ['notes', 'description', 'address', 'phone', 'email', 'contact_name', 'linked_user_id', 'serial_number'];

    public function sanitize(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $normalized = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string) $key));
            if (in_array($normalized, ['password', 'passwordconfirmation', 'passwordhash', 'temporarypassword', 'newpassword', 'oldpassword', 'currentpassword', 'passwd', 'remembertoken', 'sessionid', 'rawsessionid', 'sessionversion', 'sessiontoken', 'token', 'accesstoken', 'refreshtoken', 'csrftoken', 'xcsrftoken', 'xsrftoken', 'authorization', 'authorizationheader', 'apikey', 'apitoken', 'supabasekey', 'supabaseservicerolekey', 'dbpassword', 'databasepassword', 'sshsecret', 'privatekey', 'secret', 'clientsecret', 'cookie', 'setcookie'], true)) {
                continue;
            }
            $out[$key] = is_array($value) ? $this->sanitize($value) : (is_object($value) ? '[omitted]' : $value);
        }

        return $out;
    }

    public function snapshot(Model $model): array
    {
        return array_intersect_key($model->attributesToArray(), array_flip(self::FIELDS));
    }

    public function changes(array $before, array $after): array
    {
        $changes = [];
        foreach (self::FIELDS as $key) {
            if (! array_key_exists($key, $before) && ! array_key_exists($key, $after)) {
                continue;
            }
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if ($old !== $new) {
                $changes[$key] = ['before' => $old, 'after' => $new];
            }
        }

        return $changes;
    }

    public function changed(?User $actor, string $action, string $type, ?string $targetId, ?string $accountId, array $before, array $after, array $changedFields = []): void
    {
        $changes = $this->changes($before, $after);
        foreach (array_intersect($changedFields, self::OMITTED_FIELDS) as $field) {
            $changes[$field] = ['before' => '[not retained]', 'after' => '[not retained]'];
        }
        if ($changes) {
            $this->record($actor, $action, $type, $targetId, $accountId, ['changes' => $changes]);
        }
    }

    public function record(?User $actor, string $action, string $type, ?string $targetId, ?string $accountId = null, array $metadata = []): void
    {
        // Defense in depth: only the stable contract survives, even for direct callers.
        $changes = [];
        foreach (($metadata['changes'] ?? []) as $key => $value) {
            if (in_array($key, self::OMITTED_FIELDS, true)) {
                $changes[$key] = ['before' => '[not retained]', 'after' => '[not retained]'];
            } elseif (in_array($key, self::FIELDS, true) && is_array($value)) {
                $changes[$key] = array_intersect_key($value, array_flip(['before', 'after']));
            }
        }
        $safe = $this->sanitize(['version' => 1, 'actor_type' => $actor ? (app(PlatformPrivilegeService::class)->isSuperuser($actor) ? 'platform' : 'tenant') : 'system', 'changes' => $changes]);
        DB::table('governance_audit_logs')->insert(['id' => (string) Str::uuid(), 'actor_user_id' => $actor?->id, 'action' => $action, 'target_type' => $type, 'target_id' => $targetId, 'account_id' => $accountId, 'metadata' => json_encode($safe, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
