<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class CanonicalConfigSyncService
{
    private const OPERATIONAL_TABLES = [
        'counter_readings', 'operational_incidents', 'component_lifecycles',
        'inventory_movements', 'purchases', 'purchase_lines', 'receipts',
        'receipt_lines', 'fifo_layers', 'fifo_allocations', 'component_replacements',
        'machine_selling_prices', 'machine_operating_costs', 'operational_incident_revisions',
    ];

    private const AUTH_TABLES = [
        'users', 'account_memberships', 'account_membership_branches', 'platform_user_privileges',
    ];

    /** @param callable(array<int,array<string,mixed>>,array<string,int>):void $publishPlan */
    public function run(bool $dryRun, callable $publishPlan, ?int $expectedCreates = null): array
    {
        $config = config('canonical_day1');
        $this->validateStaticContract($config);
        $this->validateSchema();

        DB::beginTransaction();
        try {
            $this->lockAndValidateProductionAnchors($config);
            $before = $this->protectedFingerprints();
            $this->assertLegacyGrahaOperationalRecordsZero($config);
            $plan = $this->buildPlan($config);
            $counts = ['create' => count($plan), 'update' => 0, 'delete' => 0];

            if ($expectedCreates !== null && $counts['create'] !== $expectedCreates) {
                throw new RuntimeException("Expected CONFIG_CREATE={$expectedCreates}; actual deterministic plan is {$counts['create']}");
            }

            // The operator sees the complete plan while the transaction is open,
            // before any INSERT and necessarily before COMMIT.
            $publishPlan($plan, $counts);
            foreach ($plan as $operation) {
                DB::table($operation['table'])->insert($operation['values'] + [
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $this->lockAndValidateProductionAnchors($config);
            $this->assertLegacyGrahaOperationalRecordsZero($config);
            if ($before !== $this->protectedFingerprints()) {
                throw new RuntimeException('Protected Production people, Auth, supplier, or operational history changed');
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }

            return [
                'config_create' => $counts['create'], 'config_update' => 0, 'config_delete' => 0,
                'new_writes' => $dryRun ? 0 : $counts['create'], 'would_write' => $counts['create'],
                'rolled_back' => $dryRun,
            ];
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $exception;
        }
    }

    private function validateStaticContract(array $config): void
    {
        if (($config['version'] ?? null) !== 'm2.12e-minimal-safe-day1-v1') {
            throw new RuntimeException('Unknown or missing canonical Day-1 configuration version');
        }
        $creates = $config['creates'] ?? [];
        if (count($creates['counter_types'] ?? []) !== 3 || count($creates['inventory_items'] ?? []) !== 10) {
            throw new RuntimeException('Canonical source classification changed: expected 3 counter types and 10 inventory items');
        }
        if (array_keys($creates) !== ['branch', 'operational_policy', 'counter_types', 'inventory_location', 'inventory_items']
            || ($config['expected_create_count'] ?? null) !== 16) {
            throw new RuntimeException('Canonical create allowlist/count contract changed');
        }
        $ids = [$creates['branch']['id'] ?? null, $creates['inventory_location']['id'] ?? null];
        foreach (array_merge($creates['counter_types'], $creates['inventory_items']) as $row) {
            $ids[] = $row['id'] ?? null;
        }
        if (count($ids) !== 15 || count(array_unique($ids)) !== 15 || in_array(null, $ids, true)) {
            throw new RuntimeException('Production-local identity set is incomplete or duplicated');
        }
        $leaks = array_intersect(array_map('strtolower', $ids), array_map('strtolower', $config['prohibited_dev_uuids'] ?? []));
        if ($leaks !== []) {
            throw new RuntimeException('DEV UUID leakage in canonical write identities: '.implode(',', $leaks));
        }
        $payloadUuids = [];
        array_walk_recursive($creates, function ($value) use (&$payloadUuids): void {
            if (is_string($value)) {
                $payloadUuids[] = strtolower($value);
            }
        });
        $payloadLeaks = array_intersect($payloadUuids, array_map('strtolower', $config['prohibited_dev_uuids'] ?? []));
        if ($payloadLeaks !== []) {
            throw new RuntimeException('DEV UUID leakage in canonical write payload: '.implode(',', array_unique($payloadLeaks)));
        }
        foreach ($ids as $id) {
            if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) {
                throw new RuntimeException("Non-Production-local UUIDv4 identity: {$id}");
            }
        }
    }

    private function validateSchema(): void
    {
        $required = array_merge(
            ['accounts', 'branches', 'machines', 'machine_models', 'counter_types', 'account_operational_permissions', 'component_catalogs', 'inventory_locations', 'inventory_items', 'operational_people', 'operational_person_branches', 'inventory_suppliers'],
            self::OPERATIONAL_TABLES,
            self::AUTH_TABLES,
        );
        $missing = array_values(array_filter($required, fn (string $table) => ! Schema::hasTable($table)));
        if ($missing !== []) {
            throw new RuntimeException('Schema parity blocker; missing tables: '.implode(',', $missing));
        }
        if (! in_array(DB::getDriverName(), ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Only Laravel MySQL or disposable SQLite targets are supported');
        }
        if (app()->environment('production') && DB::getDatabaseName() !== config('canonical_day1.production.database')) {
            throw new RuntimeException('Production database identity mismatch');
        }
    }

    private function lockAndValidateProductionAnchors(array $config): void
    {
        $p = $config['production'];
        $account = DB::table('accounts')->where('id', $p['account']['id'])->lockForUpdate()->first();
        $this->assertExactObject('Production account', $account, $p['account']);
        $branch = DB::table('branches')->where('id', $p['tuparev']['id'])->lockForUpdate()->first();
        $this->assertExactObject('Production Tuparev', $branch, $p['tuparev'] + ['account_id' => $p['account']['id'], 'is_active' => true, 'archived_at' => null]);
        $model = DB::table('machine_models')->where('id', $p['machine_model_id'])->lockForUpdate()->first();
        if (! $model || ! $this->same($model->is_active, true)) {
            throw new RuntimeException('Production C1070 model identity/status mismatch');
        }
        $machine = DB::table('machines')->where('id', $p['machine']['id'])->lockForUpdate()->first();
        $this->assertExactObject('Production primary machine', $machine, [
            'id' => $p['machine']['id'], 'account_id' => $p['account']['id'],
            'branch_id' => $p['tuparev']['id'], 'machine_model_id' => $p['machine_model_id'],
            'machine_code' => $p['machine']['code'], 'status' => 'active',
        ]);
        $master = DB::table('users')->where('id', $p['master_user_id'])->lockForUpdate()->first();
        if (! $master || (isset($master->status) && $master->status !== 'active')) {
            throw new RuntimeException('Existing Production master user identity/status mismatch');
        }
        if (DB::table('users')->where('id', '<>', $p['master_user_id'])->exists()) {
            throw new RuntimeException('Auth safety gate failed: Production must contain the existing master identity only');
        }
    }

    private function buildPlan(array $config): array
    {
        $c = $config['creates'];
        $plan = [];
        $this->planExact($plan, 'branches', ['account_id' => $c['branch']['account_id'], 'code' => $c['branch']['code']], $this->writeValues($c['branch']), 'GRAHA_BRANCH_MASTER');
        $this->planExact($plan, 'account_operational_permissions', ['account_id' => $c['operational_policy']['account_id']], $this->writeValues($c['operational_policy']), 'OPERATIONAL_POLICY');
        foreach ($c['counter_types'] as $row) {
            $this->planExact($plan, 'counter_types', ['code' => $row['code']], $row, 'COUNTER_TYPE');
        }
        $this->planExact($plan, 'inventory_locations', ['account_id' => $c['inventory_location']['account_id'], 'code' => $c['inventory_location']['code']], $this->writeValues($c['inventory_location']), 'TUPAREV_INVENTORY_LOCATION');
        foreach ($c['inventory_items'] as $row) {
            $component = DB::table('component_catalogs')->where('id', $row['component_id'])->first();
            $this->assertExactObject("Component crosswalk {$row['component_code']}", $component, ['id' => $row['component_id'], 'code' => $row['component_code'], 'is_active' => true]);
            if (! DB::table('machine_components')->where('machine_id', $config['production']['machine']['id'])->where('component_id', $row['component_id'])->where('status', 'configured')->exists()) {
                throw new RuntimeException("Component crosswalk {$row['component_code']} is not configured on the Production C1070 machine");
            }
            $values = [
                'id' => $row['id'], 'account_id' => $config['production']['account']['id'],
                'component_id' => $row['component_id'], 'sku' => $row['sku'], 'name' => $row['name'],
                'category' => $row['category'], 'unit' => 'pcs', 'minimum_stock' => null,
                'is_active' => true, 'archived_at' => null,
            ];
            $linkedItems = DB::table('inventory_items')->where('account_id', $values['account_id'])->where('component_id', $values['component_id'])->get();
            if ($linkedItems->isNotEmpty() && ! $linkedItems->contains(fn ($item) => $item->id === $values['id'])) {
                throw new RuntimeException("Canonical inventory gap changed for {$row['component_code']}; an unclassified linked item exists");
            }
            $this->planExact($plan, 'inventory_items', ['account_id' => $values['account_id'], 'sku' => $values['sku']], $values, 'CANONICAL_INVENTORY_ITEM');
        }

        return $plan;
    }

    private function planExact(array &$plan, string $table, array $key, array $values, string $group): void
    {
        $byKey = DB::table($table)->where($key)->first();
        $byId = isset($values['id']) ? DB::table($table)->where('id', $values['id'])->first() : null;
        if ($byKey || $byId) {
            if (! $byKey || ($byId && ($byKey->id ?? null) !== ($byId->id ?? null))) {
                throw new RuntimeException("Identity/business-key collision in {$table}");
            }
            $this->assertExactObject("Existing {$table}", $byKey, $values);

            return;
        }
        $plan[] = ['action' => 'CREATE', 'group' => $group, 'table' => $table, 'business_key' => $key, 'values' => $values];
    }

    private function assertLegacyGrahaOperationalRecordsZero(array $config): void
    {
        $grahaId = $config['creates']['branch']['id'];
        $machineIds = DB::table('machines')->where('branch_id', $grahaId)->pluck('id');
        $locationIds = DB::table('inventory_locations')->where('branch_id', $grahaId)->pluck('id');
        $counts = [
            DB::table('machines')->where('branch_id', $grahaId)->count(),
            DB::table('inventory_locations')->where('branch_id', $grahaId)->count(),
            DB::table('operational_person_branches')->where('branch_id', $grahaId)->count(),
            DB::table('operational_incidents')->where('branch_id', $grahaId)->count(),
            $machineIds->isEmpty() ? 0 : DB::table('counter_readings')->whereIn('machine_id', $machineIds)->count(),
            $locationIds->isEmpty() ? 0 : DB::table('inventory_movements')->whereIn('location_id', $locationIds)->count(),
        ];
        if (array_sum($counts) !== 0) {
            throw new RuntimeException('LEGACY_GRAHA_OPERATIONAL_RECORDS is not zero');
        }
    }

    private function protectedFingerprints(): array
    {
        $tables = array_merge(self::AUTH_TABLES, self::OPERATIONAL_TABLES, [
            'operational_people', 'operational_person_branches', 'inventory_suppliers',
            'accounts', 'machines', 'machine_models', 'manufacturers', 'component_catalogs',
            'machine_components', 'model_profiles', 'model_profile_slots',
        ]);
        $result = [];
        foreach ($tables as $table) {
            $columns = Schema::getColumnListing($table);
            $order = in_array('id', $columns, true) ? 'id' : $columns[0];
            $rows = DB::table($table)->orderBy($order)->get()->map(fn ($row) => (array) $row)->all();
            $result[$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }

        return $result;
    }

    private function assertExactObject(string $label, ?object $actual, array $expected): void
    {
        if (! $actual) {
            throw new RuntimeException("{$label} is missing");
        }
        foreach ($expected as $column => $value) {
            if (! property_exists($actual, $column) || ! $this->same($actual->{$column}, $value)) {
                throw new RuntimeException("{$label} conflicts at {$column}");
            }
        }
    }

    private function same(mixed $actual, mixed $expected): bool
    {
        if ($expected === null) {
            return $actual === null;
        }
        if (is_bool($expected)) {
            return (bool) $actual === $expected;
        }
        if (is_numeric($actual) && is_numeric($expected)) {
            return abs((float) $actual - (float) $expected) < 0.00005;
        }

        return (string) $actual === (string) $expected;
    }

    private function writeValues(array $row): array
    {
        unset($row['semantic_source']);

        return $row;
    }
}
