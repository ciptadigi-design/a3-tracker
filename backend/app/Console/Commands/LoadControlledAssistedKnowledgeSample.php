<?php

namespace App\Console\Commands;

use App\Models\MaintenanceOfficialErrorEntry;
use App\Services\AssistedKnowledge\AssistedKnowledgeService;
use Illuminate\Console\Command;
use JsonException;

class LoadControlledAssistedKnowledgeSample extends Command
{
    protected $signature = 'maintenance:v2-load-assisted-sample
        {--manifest= : Absolute path to the controlled JSON manifest}
        {--expected-count= : Exact expected record count}
        {--apply : Persist only after every record validates}
        {--confirm= : Required APPLY-ASSISTED-<count>-V<version> acknowledgement}';

    protected $description = 'Validate or persist a bounded reviewed assisted-knowledge sample';

    public function handle(AssistedKnowledgeService $service): int
    {
        $path = (string) $this->option('manifest');
        if ($path === '' || ! is_file($path)) {
            return $this->refuse('manifest_not_found');
        }
        try {
            $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->refuse('manifest_json_invalid');
        }
        $records = $manifest['records'] ?? null;
        $version = (int) ($manifest['content_version'] ?? 0);
        $expected = (int) $this->option('expected-count');
        if (! is_array($records) || $version < 1 || $expected < 1 || count($records) !== $expected) {
            return $this->refuse('manifest_contract_mismatch');
        }

        $validated = [];
        foreach ($records as $record) {
            $entry = MaintenanceOfficialErrorEntry::with(['steps', 'applicabilities'])->find($record['official_entry_id'] ?? '');
            $errors = $entry ? $service->validate($entry, $record, (string) ($record['language'] ?? '')) : ['official_entry_missing'];
            $validated[] = compact('entry', 'record', 'errors');
            $this->line(implode('|', [
                'code='.($entry?->code ?? ($record['error_code'] ?? 'UNKNOWN')),
                'variant='.($entry?->variant_key ?? 'UNKNOWN'),
                'official_steps='.($entry?->steps->count() ?? 0),
                'assisted_steps='.count($record['solution_steps'] ?? []),
                'technical_tokens='.(collect($errors)->contains(fn ($error) => str_contains($error, 'technical_token')) ? 'FAIL' : 'PASS'),
                'warning='.(collect($errors)->contains(fn ($error) => str_starts_with($error, 'warning_')) ? 'FAIL' : 'PASS'),
                'status='.($errors === [] ? 'VALID' : 'INVALID'),
            ]));
        }
        if (collect($validated)->contains(fn ($item) => $item['errors'] !== [])) {
            return $this->refuse('sample_validation_failed');
        }

        if (! $this->option('apply')) {
            $this->info('CONTROLLED_ASSISTED_SAMPLE_VALIDATION=PASS');

            return self::SUCCESS;
        }
        if ((string) $this->option('confirm') !== "APPLY-ASSISTED-{$expected}-V{$version}") {
            return $this->refuse('confirmation_mismatch');
        }
        $metadata = $manifest['generator'] ?? [];
        foreach ($validated as $item) {
            $service->persistValid($item['entry'], $item['record'], $metadata, $version);
        }
        $this->info('CONTROLLED_ASSISTED_SAMPLE_APPLIED='.$expected);

        return self::SUCCESS;
    }

    private function refuse(string $reason): int
    {
        $this->error('CONTROLLED_ASSISTED_SAMPLE=FAIL reason='.$reason);

        return self::FAILURE;
    }
}
