<?php
declare(strict_types=1);

/**
 * Credential-free remote helper for the approved M2.12F component patch.
 * Reads the private Laravel .env passed by the wrapper. It never prints secrets.
 */

const EXPECTED_DB = 'u777904340_a3production';
const EXPECTED_MACHINE = '708e199e-7f77-4219-b278-37d0b94821d4';

function stop(string $message, int $code = 78): never
{
    fwrite(STDERR, 'APPLY_STOP_REASON=' . preg_replace('/[^A-Za-z0-9 ._:\/-]/', '?', $message) . PHP_EOL);
    exit($code);
}

function envFile(string $path): array
{
    $result = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || ! preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $match)) continue;
        $value = trim($match[2]);
        if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
            $quote = $value[0];
            $value = substr($value, 1, -1);
            if ($quote === '"') $value = stripcslashes($value);
        } else {
            $value = preg_replace('/\s+#.*$/', '', $value);
        }
        $result[$match[1]] = $value;
    }
    return $result;
}

function connect(array $env): PDO
{
    foreach (['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
        if (! isset($env[$key]) || $env[$key] === '') stop("missing {$key}");
    }
    if ($env['DB_DATABASE'] !== EXPECTED_DB) stop('Production database identity mismatch');
    $port = $env['DB_PORT'] ?? '3306';
    $pdo = new PDO("mysql:host={$env['DB_HOST']};port={$port};dbname={$env['DB_DATABASE']};charset=utf8mb4", $env['DB_USERNAME'], $env['DB_PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== EXPECTED_DB) stop('connected database identity mismatch');
    return $pdo;
}

function writeClientConfig(array $env, string $path): void
{
    $escape = static fn (mixed $value): string => str_replace(["\\", '"', "\n", "\r"], ["\\\\", '\\"', '\\n', '\\r'], (string) $value);
    $text = "[client]\nuser=\"" . $escape($env['DB_USERNAME']) . "\"\npassword=\"" . $escape($env['DB_PASSWORD']) . "\"\nhost=\"" . $escape($env['DB_HOST']) . "\"\nport=" . (int) ($env['DB_PORT'] ?? 3306) . "\ndefault-character-set=utf8mb4\n";
    if (file_put_contents($path, $text, LOCK_EX) === false || ! chmod($path, 0600)) stop('private client config write failed');
}

function jsonFile(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($decoded)) stop('artifact is not an object');
    return $decoded;
}

function same(mixed $actual, mixed $expected): bool
{
    if ($actual === null || $expected === null) return $actual === null && $expected === null;
    if (is_int($expected)) return preg_match('/^-?[0-9]+$/', (string) $actual) === 1 && (int) $actual === $expected;
    if (is_float($expected)) return is_numeric($actual) && (float) $actual === $expected;
    if (is_bool($expected)) return (bool) $actual === $expected;
    return (string) $actual === (string) $expected;
}

function validateArtifacts(array $patch, array $rollback): void
{
    $allowed = [
        'component_catalogs' => ['tracking_method'],
        'model_profile_slots' => ['baseline_expected_clicks', 'display_order', 'tracking_method'],
        'machine_components' => ['baseline_expected_clicks', 'display_order', 'source_type', 'tracking_method'],
    ];
    if (($patch['plan_only'] ?? null) !== true || ($patch['executed'] ?? null) !== false) stop('patch plan flags invalid');
    if (($rollback['plan_only'] ?? null) !== true || ($rollback['executed'] ?? null) !== false) stop('rollback plan flags invalid');
    if (count($patch['updates'] ?? []) !== 126 || count($rollback['updates'] ?? []) !== 126) stop('artifact cardinality mismatch');
    $rows = [];
    $inverse = [];
    foreach ($rollback['updates'] as $update) {
        $key = implode(':', [$update['domain'] ?? '', $update['production_id'] ?? '', $update['field'] ?? '']);
        $inverse[$key] = $update;
    }
    foreach ($patch['updates'] as $index => $update) {
        $domain = $update['domain'] ?? '';
        $field = $update['field'] ?? '';
        if (($update['operation'] ?? '') !== 'UPDATE' || ! isset($allowed[$domain]) || ! in_array($field, $allowed[$domain], true)) stop("unapproved operation at patch index {$index}");
        if (($update['sequence'] ?? null) !== $index + 1) stop("patch sequence mismatch at index {$index}");
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f-]{27}$/i', (string) ($update['production_id'] ?? ''))) stop("invalid Production row ID at index {$index}");
        if (is_string($update['proposed_value'] ?? null) && preg_match('/^[0-9a-f]{8}-[0-9a-f-]{27}$/i', $update['proposed_value'])) stop('UUID proposed as field value');
        $key = implode(':', [$domain, $update['production_id'], $field]);
        $rows[$domain . ':' . $update['production_id']] = true;
        $undo = $inverse[$key] ?? null;
        if (! $undo || ! same($undo['current_value'] ?? null, $update['proposed_value'] ?? null) || ! same($undo['proposed_value'] ?? null, $update['current_value'] ?? null)) stop("rollback inverse mismatch for {$key}");
    }
    if (count($rows) !== 60) stop('distinct Production row cardinality mismatch');
}

function fetchCurrent(PDO $pdo, array $update, bool $lock = false): mixed
{
    $table = $update['domain'];
    $field = $update['field'];
    $sql = "SELECT `{$field}` FROM `{$table}` WHERE id=?" . ($lock ? ' FOR UPDATE' : '');
    $statement = $pdo->prepare($sql);
    $statement->execute([$update['production_id']]);
    $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
    if (count($rows) !== 1) stop("row identity did not resolve exactly once for {$table}:{$update['production_id']}");
    return $rows[0];
}

function classify(PDO $pdo, array $updates, bool $lock = false): array
{
    $counts = ['match' => 0, 'already_target' => 0, 'unexpected' => 0];
    $unexpected = [];
    foreach ($updates as $update) {
        $actual = fetchCurrent($pdo, $update, $lock);
        if (same($actual, $update['current_value'])) $counts['match']++;
        elseif (same($actual, $update['proposed_value'])) $counts['already_target']++;
        else {
            $counts['unexpected']++;
            $unexpected[] = ['domain' => $update['domain'], 'production_id' => $update['production_id'], 'field' => $update['field']];
        }
    }
    return [$counts, $unexpected];
}

function tableNames(PDO $pdo): array
{
    $statement = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
    $statement->execute([EXPECTED_DB]);
    return $statement->fetchAll(PDO::FETCH_COLUMN);
}

function tableFingerprint(PDO $pdo, string $table, array $excluded = []): string
{
    $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll();
    $encoded = [];
    foreach ($rows as $row) {
        foreach ($excluded as $field) unset($row[$field]);
        ksort($row, SORT_STRING);
        $encoded[] = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
    sort($encoded, SORT_STRING);
    return hash('sha256', implode("\n", $encoded));
}

function scalar(PDO $pdo, string $sql, array $parameters = []): int|string|null
{
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    $value = $statement->fetchColumn();
    return $value === false ? null : $value;
}

function snapshot(PDO $pdo): array
{
    $counts = [];
    $fingerprints = [];
    $mutable = [
        'component_catalogs' => ['tracking_method'],
        'model_profile_slots' => ['baseline_expected_clicks', 'display_order', 'tracking_method'],
        'machine_components' => ['baseline_expected_clicks', 'display_order', 'source_type', 'tracking_method'],
    ];
    foreach (tableNames($pdo) as $table) {
        $counts[$table] = (int) scalar($pdo, "SELECT COUNT(*) FROM `{$table}`");
        $fingerprints[$table] = tableFingerprint($pdo, $table, $mutable[$table] ?? []);
    }
    $machine = $pdo->prepare('SELECT id,machine_code FROM machines WHERE id=?');
    $machine->execute([EXPECTED_MACHINE]);
    $machineRows = $machine->fetchAll();
    if (count($machineRows) !== 1 || $machineRows[0]['machine_code'] !== 'CG-TUP-A3-01') stop('Production machine identity mismatch');
    return [
        'table_counts' => $counts,
        'immutable_fingerprints' => $fingerprints,
        'component_catalogs' => (int) scalar($pdo, 'SELECT COUNT(*) FROM component_catalogs'),
        'model_profiles' => (int) scalar($pdo, 'SELECT COUNT(*) FROM model_profiles'),
        'model_profile_slots' => (int) scalar($pdo, 'SELECT COUNT(*) FROM model_profile_slots'),
        'machine_components' => (int) scalar($pdo, 'SELECT COUNT(*) FROM machine_components WHERE machine_id=?', [EXPECTED_MACHINE]),
        'configured_machine_components' => (int) scalar($pdo, 'SELECT COUNT(*) FROM machine_components WHERE machine_id=? AND status<>\'retired\'', [EXPECTED_MACHINE]),
        'lifecycles' => (int) scalar($pdo, 'SELECT COUNT(*) FROM component_lifecycles l JOIN machine_components mc ON mc.id=l.machine_component_id WHERE mc.machine_id=?', [EXPECTED_MACHINE]),
        'active_lifecycles' => (int) scalar($pdo, 'SELECT COUNT(*) FROM component_lifecycles l JOIN machine_components mc ON mc.id=l.machine_component_id WHERE mc.machine_id=? AND l.status=\'active\'', [EXPECTED_MACHINE]),
        'closed_lifecycles' => (int) scalar($pdo, 'SELECT COUNT(*) FROM component_lifecycles l JOIN machine_components mc ON mc.id=l.machine_component_id WHERE mc.machine_id=? AND l.status=\'closed\'', [EXPECTED_MACHINE]),
        'latest_counter' => (int) scalar($pdo, 'SELECT cr.reading_value FROM counter_readings cr JOIN counter_types ct ON ct.id=cr.counter_type_id WHERE cr.machine_id=? AND cr.status=\'effective\' AND LOWER(TRIM(ct.code))=\'total_impressions\' ORDER BY cr.observed_at DESC,cr.created_at DESC,cr.id DESC LIMIT 1', [EXPECTED_MACHINE]),
        'machine_id' => $machineRows[0]['id'],
        'machine_code' => $machineRows[0]['machine_code'],
    ];
}

function validateInvariants(array $state): void
{
    $expected = ['component_catalogs' => 28, 'model_profiles' => 1, 'model_profile_slots' => 28, 'machine_components' => 28, 'configured_machine_components' => 28, 'lifecycles' => 47, 'active_lifecycles' => 0, 'closed_lifecycles' => 47, 'latest_counter' => 1441597];
    foreach ($expected as $key => $value) if (($state[$key] ?? null) !== $value) stop("invariant {$key} mismatch");
    if (($state['machine_id'] ?? null) !== EXPECTED_MACHINE || ($state['machine_code'] ?? null) !== 'CG-TUP-A3-01') stop('machine invariant mismatch');
}

function validateUnchanged(array $before, array $after): void
{
    if ($before['table_counts'] !== $after['table_counts']) stop('database row counts changed');
    if ($before['immutable_fingerprints'] !== $after['immutable_fingerprints']) stop('prohibited or immutable data changed');
}

function applyPatch(PDO $pdo, array $updates): array
{
    $pdo->beginTransaction();
    try {
        [$counts, $unexpected] = classify($pdo, $updates, true);
        if ($counts['unexpected'] !== 0) stop('transactional precondition changed unexpectedly');
        $applied = 0;
        $failed = 0;
        foreach ($updates as $update) {
            $actual = fetchCurrent($pdo, $update, false);
            if (same($actual, $update['proposed_value'])) continue;
            if (! same($actual, $update['current_value'])) {
                $failed++;
                throw new RuntimeException('compare-and-set precondition changed');
            }
            $table = $update['domain'];
            $field = $update['field'];
            $statement = $pdo->prepare("UPDATE `{$table}` SET `{$field}`=? WHERE id=? AND `{$field}` <=> ?");
            $statement->execute([$update['proposed_value'], $update['production_id'], $update['current_value']]);
            if ($statement->rowCount() !== 1) {
                $failed++;
                throw new RuntimeException('compare-and-set affected row count was not one');
            }
            $applied++;
        }
        [$targetCounts] = classify($pdo, array_map(static fn (array $update): array => $update + [], $updates), false);
        foreach ($updates as $update) {
            if (! same(fetchCurrent($pdo, $update), $update['proposed_value'])) throw new RuntimeException('target value validation failed inside transaction');
        }
        $pdo->commit();
        return ['applied' => $applied, 'already_target' => $counts['already_target'], 'failed' => $failed];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

$mode = $argv[1] ?? '';
$envPath = $argv[2] ?? '';
$env = envFile($envPath);
if ($mode === 'client-config') {
    connect($env);
    writeClientConfig($env, $argv[3] ?? stop('client config output missing'));
    echo "CLIENT_CONFIG=READY\n";
    exit(0);
}
if ($mode !== 'run') stop('unsupported helper mode');

$patch = jsonFile($argv[3] ?? stop('patch path missing'));
$rollback = jsonFile($argv[4] ?? stop('rollback path missing'));
$reportPath = $argv[5] ?? stop('report path missing');
validateArtifacts($patch, $rollback);
$pdo = connect($env);

try {
    $before = snapshot($pdo);
    validateInvariants($before);

    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    [$auditCounts, $unexpected] = classify($pdo, $patch['updates']);
    $pdo->rollBack();
    echo 'PRECONDITION_MATCH_COUNT=' . $auditCounts['match'] . PHP_EOL;
    echo 'PRECONDITION_ALREADY_TARGET_COUNT=' . $auditCounts['already_target'] . PHP_EOL;
    echo 'PRECONDITION_UNEXPECTED_COUNT=' . $auditCounts['unexpected'] . PHP_EOL;
    if ($auditCounts['unexpected'] !== 0) stop('read-only precondition audit found unexpected state');
    echo "PATCH_PRECONDITION_AUDIT=PASS\n";

    $result = applyPatch($pdo, $patch['updates']);
    echo "PATCH_TRANSACTION=COMMITTED\n";
    echo 'PATCH_APPLIED_FIELD_COUNT=' . $result['applied'] . PHP_EOL;
    echo 'PATCH_ALREADY_TARGET_COUNT=' . $result['already_target'] . PHP_EOL;
    echo 'PATCH_FAILED_COMPARE_AND_SET_COUNT=' . $result['failed'] . PHP_EOL;

    $after = snapshot($pdo);
    validateInvariants($after);
    validateUnchanged($before, $after);
    [$postCounts] = classify($pdo, array_map(static function (array $update): array {
        return array_merge($update, ['current_value' => $update['proposed_value'], 'proposed_value' => $update['proposed_value']]);
    }, $patch['updates']));
    if ($postCounts['match'] !== 126) stop('post-patch target count mismatch');
    [$rollbackCounts] = classify($pdo, $rollback['updates']);
    if ($rollbackCounts['match'] !== 126 || $rollbackCounts['unexpected'] !== 0) stop('rollback artifact is not applicable');

    $report = [
        'checkpoint' => 'M2.12F_COMPONENT_CONFIG_PRODUCTION_APPLY',
        'status' => 'READY',
        'patch_artifact_validation' => 'PASS',
        'rollback_artifact_validation' => 'PASS',
        'precondition' => $auditCounts,
        'transaction' => ['status' => 'COMMITTED'] + $result,
        'post_patch_target_value_match_count' => $postCounts['match'],
        'production_row_count_change' => 0,
        'rollback_plan_ready' => true,
        'before' => $before,
        'after' => $after,
    ];
    if (file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX) === false || ! chmod($reportPath, 0600)) stop('private report write failed');
    echo "COMPONENT_CATALOG_COUNT={$after['component_catalogs']}\n";
    echo "MODEL_PROFILE_COUNT={$after['model_profiles']}\n";
    echo "PROFILE_SLOT_COUNT={$after['model_profile_slots']}\n";
    echo "MACHINE_COMPONENT_COUNT={$after['machine_components']}\n";
    echo "CONFIGURED_C1070_SLOT_COUNT={$after['configured_machine_components']}\n";
    echo "LIFECYCLE_COUNT={$after['lifecycles']}\n";
    echo "ACTIVE_LIFECYCLE_COUNT={$after['active_lifecycles']}\n";
    echo "CLOSED_LIFECYCLE_COUNT={$after['closed_lifecycles']}\n";
    echo "LATEST_COUNTER={$after['latest_counter']}\n";
    echo "PRODUCTION_MACHINE_ID={$after['machine_id']}\n";
    echo "PRODUCTION_MACHINE_CODE={$after['machine_code']}\n";
    echo "POST_PATCH_TARGET_VALUE_MATCH_COUNT={$postCounts['match']}\n";
    echo "PRODUCTION_ROW_COUNT_CHANGE=0\n";
    echo "PROHIBITED_TABLE_FINGERPRINTS_UNCHANGED=PASS\n";
    echo "IMMUTABLE_TARGET_FIELDS_UNCHANGED=PASS\n";
    echo "ROLLBACK_PLAN_READY=YES\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    stop('controlled component apply failed (' . get_class($error) . ')');
}
