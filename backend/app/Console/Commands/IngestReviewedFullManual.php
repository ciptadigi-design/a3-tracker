<?php

namespace App\Console\Commands;

use App\Models\MaintenanceDocument;
use App\Services\OfficialKnowledgeIngestion\FullManualDatasetPreparer;
use App\Services\OfficialKnowledgeIngestion\FullManualIngestionService;
use App\Services\OfficialKnowledgeIngestion\OfficialDocumentSourceResolver;
use App\Services\OfficialKnowledgeIngestion\ReviewedFullManualContract;
use App\Services\OfficialKnowledgeIngestion\ReviewedFullManualContractProvider;
use Illuminate\Console\Command;
use Throwable;

final class IngestReviewedFullManual extends Command
{
    protected $signature = 'maintenance:v2-ingest-full-manual
        {--document= : Existing authoritative Maintenance Document UUID}
        {--pdf= : Exact canonical local path resolved from the document storage metadata}
        {--contract= : Reviewed contract name}
        {--expected-sha256= : Exact reviewed PDF SHA-256}
        {--expected-dataset-digest= : Exact reviewed eligible dataset digest}
        {--expected-discovered= : Exact reviewed discovered count}
        {--expected-eligible= : Exact reviewed eligible count}
        {--expected-environment= : Exact APP_ENV expected by the operator}
        {--confirm= : Contract-specific Production apply interlock}
        {--preview : Parse, validate and report without writes}
        {--apply : Atomically apply or verify an idempotent no-op rerun}
        {--verify : Read-only post-ingestion integrity verification}';

    protected $description = 'Preview, apply, or verify the reviewed V2.1 full-manual official dataset';

    public function handle(
        ReviewedFullManualContractProvider $contracts,
        OfficialDocumentSourceResolver $resolver,
        FullManualDatasetPreparer $preparer,
        FullManualIngestionService $service,
    ): int {
        $modes = array_filter(['preview' => (bool) $this->option('preview'), 'apply' => (bool) $this->option('apply'), 'verify' => (bool) $this->option('verify')]);
        if (count($modes) !== 1) {
            return $this->refuse('Specify exactly one of --preview, --apply, or --verify.');
        }
        $mode = array_key_first($modes);
        $contract = $contracts->find(trim((string) $this->option('contract')));
        if ($contract === null) {
            return $this->refuse('A known reviewed --contract is required.');
        }
        $document = MaintenanceDocument::query()->find(trim((string) $this->option('document')));
        if ($document === null) {
            return $this->refuse('The explicit --document record does not exist.');
        }

        if ($mode === 'apply' && ($reason = $this->applyGuardMismatch($contract)) !== null) {
            return $this->refuse($reason);
        }

        $source = null;
        try {
            $source = $resolver->resolve(
                $document,
                trim((string) $this->option('pdf')),
                $contract::SOURCE_SHA256,
            );
            $dataset = $preparer->prepare($source, $contract);
            $releaseSha = strtolower((string) config('release.git_sha', 'unknown'));
            $report = match ($mode) {
                'preview' => $service->preview($document, $source, $dataset, $contract, $releaseSha),
                'apply' => $service->apply($document, $source, $dataset, $contract, $releaseSha),
                'verify' => [
                    'mode' => 'VERIFY',
                    'document_id' => $document->id,
                    'source_pdf_sha256' => $source->sha256,
                    'contract_name' => $contract::NAME,
                    ...$service->verify($document, $dataset, $contract),
                    'mutation_performed' => false,
                ],
            };
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->line('FULL_MANUAL_'.strtoupper($mode).'=PASS');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            return $this->refuse($exception->getMessage());
        } finally {
            $source?->cleanup();
        }
    }

    private function applyGuardMismatch(ReviewedFullManualContract $contract): ?string
    {
        $expected = $contract->expected();
        $provided = [
            '--expected-sha256' => [(string) $this->option('expected-sha256'), $contract::SOURCE_SHA256],
            '--expected-dataset-digest' => [(string) $this->option('expected-dataset-digest'), $contract::DATASET_DIGEST],
            '--expected-discovered' => [(string) $this->option('expected-discovered'), (string) $expected['discovered']],
            '--expected-eligible' => [(string) $this->option('expected-eligible'), (string) $expected['eligible']],
            '--expected-environment' => [(string) $this->option('expected-environment'), app()->environment()],
        ];
        foreach ($provided as $option => [$actual, $required]) {
            if ($actual === '' || ! hash_equals($required, $actual)) {
                return "{$option} must exactly match the reviewed/runtime value.";
            }
        }
        if (app()->environment('production') && ! hash_equals($contract::CONFIRMATION, (string) $this->option('confirm'))) {
            return 'Production apply requires the exact contract-specific --confirm token and separate external authorization.';
        }

        return null;
    }

    private function refuse(string $reason): int
    {
        $this->error('FULL_MANUAL_INGESTION_REFUSED='.$reason);

        return self::FAILURE;
    }
}
