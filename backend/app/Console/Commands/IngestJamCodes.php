<?php

namespace App\Console\Commands;

use App\Models\MaintenanceDocument;
use App\Services\OfficialKnowledgeIngestion\Jam\JamCodeContract;
use App\Services\OfficialKnowledgeIngestion\Jam\JamCodeDatasetPreparer;
use App\Services\OfficialKnowledgeIngestion\Jam\JamCodeIngestionService;
use App\Services\OfficialKnowledgeIngestion\OfficialDocumentSourceResolver;
use Illuminate\Console\Command;
use Throwable;

final class IngestJamCodes extends Command
{
    protected $signature = 'maintenance:v2-ingest-jam-codes
        {--document= : Existing authoritative Maintenance Document UUID}
        {--pdf= : Exact canonical local path resolved from the document storage metadata}
        {--expected-dataset-digest= : Exact reviewed dataset digest}
        {--expected-environment= : Exact APP_ENV expected by the operator}
        {--confirm= : Contract-specific Production apply interlock}
        {--preview : Parse, validate and report without writes}
        {--apply : Additively create the reviewed jam-code entries (or verify an identical rerun)}
        {--verify : Read-only post-ingestion verification}';

    protected $description = 'Preview, apply or verify the reviewed additive official J-code (jam indication) dataset';

    public function handle(OfficialDocumentSourceResolver $resolver, JamCodeDatasetPreparer $preparer, JamCodeIngestionService $service): int
    {
        $modes = array_filter(['preview' => (bool) $this->option('preview'), 'apply' => (bool) $this->option('apply'), 'verify' => (bool) $this->option('verify')]);
        if (count($modes) !== 1) {
            return $this->refuse('Specify exactly one of --preview, --apply, or --verify.');
        }
        $mode = array_key_first($modes);
        $document = MaintenanceDocument::query()->find(trim((string) $this->option('document')));
        if ($document === null) {
            return $this->refuse('The explicit --document record does not exist.');
        }

        $source = null;
        try {
            $contract = JamCodeContract::reviewed();
            if ($mode === 'apply' && ($reason = $this->applyGuardMismatch($contract)) !== null) {
                return $this->refuse($reason);
            }
            $source = $resolver->resolve($document, trim((string) $this->option('pdf')), $contract->sourceSha256);
            $dataset = $preparer->prepare($source, $contract);
            $report = match ($mode) {
                'preview' => $service->preview($document, $dataset, $contract, $source->sha256),
                'apply' => $service->apply($document, $source, $dataset, $contract, strtolower((string) config('release.git_sha', 'unknown'))),
                'verify' => ['mode' => 'VERIFY', 'document_id' => $document->id, 'dataset_digest' => $dataset['digest'], ...$service->verify($document, $dataset, $contract, $source->sha256), 'mutation_performed' => false],
            };
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->line('JAM_CODE_'.strtoupper($mode).'=PASS');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            return $this->refuse($exception->getMessage());
        } finally {
            $source?->cleanup();
        }
    }

    private function applyGuardMismatch(JamCodeContract $contract): ?string
    {
        foreach ([
            '--expected-dataset-digest' => [(string) $this->option('expected-dataset-digest'), $contract->datasetDigest],
            '--expected-environment' => [(string) $this->option('expected-environment'), app()->environment()],
        ] as $option => [$actual, $required]) {
            if ($actual === '' || ! hash_equals($required, $actual)) {
                return "{$option} must exactly match the reviewed/runtime value.";
            }
        }
        if (app()->environment('production') && ! hash_equals($contract->confirmation(), (string) $this->option('confirm'))) {
            return 'Production apply requires the exact contract-specific --confirm token and separate external authorization.';
        }

        return null;
    }

    private function refuse(string $reason): int
    {
        $this->error('JAM_CODE_INGESTION_REFUSED='.$reason);

        return self::FAILURE;
    }
}
