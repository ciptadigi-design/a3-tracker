<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class LegacyImportManifestValidator
{
    // Laravel/Postgres fixtures use deterministic UUID-shaped identifiers,
    // including the all-zero-version counter-type ID; validate shape without
    // imposing RFC version bits.
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const DEV_IDS = [
        '357e420a-c9ea-4404-9da4-f254c5dce5ef',
        '76d3c7ab-55c3-40f7-b133-0ef54a448893',
        'b4ca07ee-c588-404d-abcf-b6a029e68776',
    ];

    public function validate(array $m): array
    {
        foreach (['version','app_sha','source','target','disposition','masters','records','invariants','crosswalk','safety'] as $key) {
            if (! array_key_exists($key, $m)) throw new InvalidArgumentException("Manifest field missing: {$key}");
        }
        if ($m['version'] !== 'm2.12e-neutral-import-v1') throw new InvalidArgumentException('Unsupported manifest version');
        if (! preg_match('/^[0-9a-f]{40}$/i', (string) $m['app_sha'])) throw new InvalidArgumentException('Invalid APP_SHA');
        $source = $m['source'];
        if (($source['count'] ?? null) !== 529) throw new InvalidArgumentException('Source count mismatch');
        $expected = [
            'click_history'=>'a577d071b32150c7ad13fd4b70c0fe6d9c4d3a7be08c27ad3efb8679cdeca537',
            'part_replacements'=>'2dc08b4a2d2f6dcaf354732c44823485e895da922aa9583d7b5a2c8f4a2e207e',
            'error_logs'=>'65d17c0c54b712ee04c5848e83147a2f892c1face3a48504a19e3d2a1e4cf35b',
            'inventory_parts'=>'ffcd9463f006fc531987f7b3a17c0f1d3d9dd58a2c47ffef21252ef3347ca632',
            'part_purchases'=>'6354204d285987b56764268ff22a06480003886528470401fdfe19766d7ee306',
        ];
        if (($source['fingerprints'] ?? []) !== $expected) throw new InvalidArgumentException('Source fingerprints mismatch');
        $target = $m['target'];
        foreach (['account_id','branch_id','machine_model_id','machine_id','counter_type_id'] as $key) {
            if (! preg_match(self::UUID, (string) ($target[$key] ?? ''))) throw new InvalidArgumentException("Invalid target {$key}");
        }
        $expectedDisposition = ['IMPORT'=>480,'MERGE'=>0,'SKIP_DUPLICATE'=>2,'ARCHIVE_ONLY'=>45,'APPROVED_EXCLUDE'=>2,'MANUAL_REVIEW'=>0];
        if (($m['disposition'] ?? []) !== $expectedDisposition) throw new InvalidArgumentException('Disposition mismatch');
        if (($m['invariants']['planned_counter_readings'] ?? $m['invariants']['counters'] ?? null) !== 183 || ($m['invariants']['latest_counter'] ?? null) !== 1441597 || ($m['invariants']['planned_component_lifecycles'] ?? null) !== 47 || ($m['invariants']['planned_purchases'] ?? null) !== 161 || ($m['invariants']['planned_purchase_units'] ?? null) !== 208 || ($m['invariants']['planned_purchase_total_idr'] ?? null) !== 371029998 || ($m['invariants']['planned_operational_incidents'] ?? null) !== 89) throw new InvalidArgumentException('Domain parity invariant mismatch');
        foreach (['legacy_receipts','legacy_inventory_movements','legacy_fifo_layers','fake_stock','unknown_cost_coerced_to_zero','graha_leakage','dev_target_uuid_leakage','unresolved_mappings'] as $key) if (($m['safety'][$key] ?? null) !== 0) throw new InvalidArgumentException("Unsafe manifest invariant: {$key}");
        if (($m['safety']['ijal_identity_fabricated'] ?? true) !== false) throw new InvalidArgumentException('ijal identity fabrication detected');
        $seen = [];
        foreach ($m['crosswalk'] as $row) {
            $sourceKey = ($row['source_table'] ?? '').':'.($row['source_id'] ?? '');
            if (isset($seen[$sourceKey])) throw new InvalidArgumentException("Duplicate source disposition: {$sourceKey}");
            $seen[$sourceKey] = true;
            if (in_array((string) ($row['target_id'] ?? ''), self::DEV_IDS, true)) throw new InvalidArgumentException('DEV target UUID leakage');
        }
        $encoded = json_encode($m, JSON_THROW_ON_ERROR);
        foreach (self::DEV_IDS as $id) if (str_contains($encoded, $id) && str_contains($encoded, 'target_id')) throw new InvalidArgumentException('DEV target UUID leakage');
        foreach (($m['records']['counters'] ?? []) as $row) if (strtolower((string) ($row['rawOperator'] ?? '')) === 'ijal' && (($row['operator']['id'] ?? null) !== null || ($row['operator']['name'] ?? null) !== null)) throw new InvalidArgumentException('ijal must remain archive text only');
        foreach (($m['records']['lifecycles'] ?? []) as $row) {
            foreach (['id','machineComponentId'] as $key) if (! preg_match(self::UUID, (string) ($row[$key] ?? ''))) throw new InvalidArgumentException("Invalid lifecycle {$key}");
            foreach (['installed','removed'] as $key) if (($row[$key] ?? null) !== null && (! is_numeric($row[$key]) || (float) $row[$key] < 0)) throw new InvalidArgumentException("Invalid lifecycle {$key}");
            if (($row['installed'] ?? null) !== null && ($row['removed'] ?? null) !== null && (float) $row['removed'] < (float) $row['installed']) throw new InvalidArgumentException('Invalid lifecycle counter chronology');
        }
        if (DB::getDriverName() !== 'mysql' && DB::getDriverName() !== 'sqlite') throw new InvalidArgumentException('MySQL/SQLite importer target required');
        return $m;
    }
}
