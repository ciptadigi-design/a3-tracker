<?php

namespace App\Console\Commands;

use App\Services\LegacyImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LegacyImport extends Command
{
    protected $signature = 'a3:legacy-import {--manifest= : Neutral manifest JSON path} {--dry-run : Validate and roll back} {--apply : Explicitly apply to the current MySQL target} {--confirm-production : Required acknowledgement when APP_ENV=production} {--inject-failure= : Disposable-target failure injection}';
    protected $description = 'Apply a canonical neutral legacy import manifest transactionally to Laravel/MySQL';

    public function handle(LegacyImportService $service): int
    {
        $path = (string) $this->option('manifest');
        if ($path === '' || ! is_file($path)) throw new RuntimeException('A readable --manifest path is required');
        if (! $this->option('apply') && ! $this->option('dry-run')) throw new RuntimeException('Fail closed: specify --dry-run or explicit --apply');
        if ($this->option('apply') && app()->environment('production') && ! $this->option('confirm-production')) throw new RuntimeException('Production apply requires --confirm-production');
        if (DB::getDriverName() !== 'mysql' && DB::getDriverName() !== 'sqlite') throw new RuntimeException('Only Laravel MySQL/SQLite targets are supported');
        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $result = $service->apply($manifest, ! $this->option('apply'), $this->option('inject-failure'));
        $this->line(json_encode(['manifest'=>$path,'mode'=>$this->option('apply') ? 'apply' : 'dry-run','transaction_guard'=>'PASS','new_writes'=>$result['new_writes'],'would_write'=>$result['would_write'],'rolled_back'=>$result['rolled_back']], JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
