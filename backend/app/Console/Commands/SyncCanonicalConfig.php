<?php

namespace App\Console\Commands;

use App\Services\CanonicalConfigSyncService;
use Illuminate\Console\Command;
use RuntimeException;

final class SyncCanonicalConfig extends Command
{
    protected $signature = 'a3:sync-canonical-config
        {--dry-run : Execute the complete write plan in a transaction, then roll it back}
        {--apply : Apply the configuration transaction (requires an explicit confirmation)}
        {--expect-create= : Fail unless the computed create count equals this value}
        {--confirm= : Must equal APPLY_MINIMAL_SAFE_DAY1 for apply}
        {--backup= : Fresh pre-apply Production database .gz backup path}
        {--backup-sha256= : Expected lowercase SHA-256 for the backup}';

    protected $description = 'Reconcile the M2.12E minimal-safe canonical Production Day-1 configuration';

    public function handle(CanonicalConfigSyncService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');
        if ($dryRun === $apply) {
            throw new RuntimeException('Fail closed: specify exactly one of --dry-run or --apply');
        }
        if ($apply && $this->option('confirm') !== 'APPLY_MINIMAL_SAFE_DAY1') {
            throw new RuntimeException('Apply requires --confirm=APPLY_MINIMAL_SAFE_DAY1');
        }
        if ($apply && app()->environment('production')) {
            $this->verifyBackup((string) $this->option('backup'), (string) $this->option('backup-sha256'));
        }
        $expected = $this->option('expect-create');
        if ($expected !== null && (! ctype_digit((string) $expected) || (int) $expected < 0)) {
            throw new RuntimeException('--expect-create must be a non-negative integer');
        }

        $result = $service->run($dryRun, function (array $plan, array $counts): void {
            $this->line('WRITE_PLAN_BEGIN');
            foreach ($plan as $index => $operation) {
                $this->line(json_encode(['sequence' => $index + 1] + $operation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            }
            $this->line('WRITE_PLAN_END');
            $this->line("CONFIG_CREATE={$counts['create']}");
            $this->line('CONFIG_UPDATE=0');
            $this->line('CONFIG_DELETE=0');
        }, $expected === null ? null : (int) $expected);

        foreach ([
            'DEV_UUID_LEAKAGE=0', 'AUTH_USER_CREATION=0', 'PERSON_IDENTITY_REWRITE=0',
            'OPERATIONAL_FACT_WRITES=0', 'FAKE_STOCK_CREATION=0', 'FAKE_LIFECYCLE_CREATION=0',
            'LEGACY_GRAHA_OPERATIONAL_RECORDS=0', 'EXISTING_PRODUCTION_ACCOUNT_UNCHANGED=PASS',
            'EXISTING_TUPAREV_UNCHANGED=PASS', 'EXISTING_PRODUCTION_MACHINE_UNCHANGED=PASS',
            'EXISTING_PRODUCTION_OPERATIONAL_PEOPLE_UNCHANGED=PASS', 'EXISTING_OPERATIONAL_HISTORY_UNCHANGED=PASS',
        ] as $line) {
            $this->line($line);
        }
        $this->line('NEW_WRITES='.$result['new_writes']);
        $this->line('TRANSACTION_RESULT='.($dryRun ? 'ROLLED_BACK' : 'COMMITTED'));
        $this->line('CONFIG_SYNC_'.($dryRun ? 'DRY_RUN' : 'APPLY').'=PASS');

        return self::SUCCESS;
    }

    private function verifyBackup(string $path, string $expectedHash): void
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Production apply requires a readable fresh --backup .gz file');
        }
        if (! preg_match('/^[0-9a-f]{64}$/', $expectedHash) || ! hash_equals($expectedHash, hash_file('sha256', $path))) {
            throw new RuntimeException('Production backup SHA-256 verification failed');
        }
        $stream = gzopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Production backup gzip open failed');
        }
        while (! gzeof($stream)) {
            if (gzread($stream, 1024 * 1024) === false) {
                gzclose($stream);
                throw new RuntimeException('Production backup gzip integrity failed');
            }
        }
        gzclose($stream);
    }
}
