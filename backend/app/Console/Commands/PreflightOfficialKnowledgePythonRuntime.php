<?php

namespace App\Console\Commands;

use App\Services\OfficialKnowledgeIngestion\OfficialKnowledgePythonRuntime;
use Illuminate\Console\Command;
use Throwable;

final class PreflightOfficialKnowledgePythonRuntime extends Command
{
    protected $signature = 'maintenance:v2-extractor-runtime-preflight';

    protected $description = 'Verify the pinned private Python runtime for reviewed official extraction';

    public function handle(OfficialKnowledgePythonRuntime $runtime): int
    {
        try {
            $identity = $runtime->preflight();
            $this->line('CONFIGURED_EXECUTABLE_ABSOLUTE=PASS');
            $this->line('EXECUTABLE_IDENTITY='.basename($identity['executable']));
            $this->line('PYTHON_VERSION='.$identity['python_version']);
            $this->line('PYPDF_VERSION='.$identity['pypdf_version']);
            $this->line('CRYPTOGRAPHY_VERSION='.$identity['cryptography_version']);
            $this->line('OFFICIAL_EXTRACTION_RUNTIME_PREFLIGHT=PASS');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('OFFICIAL_EXTRACTION_RUNTIME_PREFLIGHT=FAIL');
            $this->error('REASON='.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
