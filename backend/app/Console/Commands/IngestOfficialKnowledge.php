<?php

namespace App\Console\Commands;

use App\Models\MaintenanceDocument;
use App\Services\OfficialKnowledgeIngestion\OfficialKnowledgeIngestionAction;
use App\Services\OfficialKnowledgeIngestion\OfficialKnowledgeIngestionPolicy;
use App\Services\OfficialKnowledgeIngestion\OfficialKnowledgeIngestionService;
use App\Services\OfficialKnowledgeIngestion\OfficialKnowledgeManifestProvider;
use App\Services\OfficialKnowledgeIngestion\OfficialPdfTextAcquirer;
use App\Services\OfficialKnowledgeParsing\ParsedOfficialErrorEntry;
use App\Services\OfficialKnowledgeParsing\ParserOutcome;
use App\Services\OfficialKnowledgeParsing\PdfPageTextNormalizer;
use App\Services\OfficialKnowledgeParsing\SemanticErrorCodeParser;
use App\Services\OfficialKnowledgeParsing\SourceTextChunk;
use Illuminate\Console\Command;

final class IngestOfficialKnowledge extends Command
{
    protected $signature = 'maintenance:v2-ingest-official
        {--document= : Existing maintenance document UUID}
        {--pdf= : Readable local source PDF path}
        {--sha256= : Exact expected lowercase SHA-256 of the PDF}
        {--manifest= : Explicit controlled section manifest name}
        {--dry-run : Prepare and report actions without official-table writes}
        {--apply : Persist the prepared batch atomically}';

    protected $description = 'Locally ingest an explicitly controlled official-maintenance section manifest';

    public function handle(
        OfficialKnowledgeManifestProvider $manifests,
        OfficialPdfTextAcquirer $acquirer,
        PdfPageTextNormalizer $normalizer,
        SemanticErrorCodeParser $parser,
        OfficialKnowledgeIngestionService $ingestion,
    ): int {
        if ($this->laravel->environment('production')) {
            return $this->refuse('APP_ENV=production is forbidden for controlled official ingestion.');
        }

        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');
        if ($dryRun === $apply) {
            return $this->refuse('Specify exactly one of --dry-run or --apply.');
        }

        $documentId = trim((string) $this->option('document'));
        $document = $documentId === '' ? null : MaintenanceDocument::query()->find($documentId);
        if ($document === null) {
            return $this->refuse('The explicit --document record does not exist.');
        }

        $pdfPath = (string) $this->option('pdf');
        if ($pdfPath === '' || ! is_file($pdfPath) || ! is_readable($pdfPath)) {
            return $this->refuse('The explicit --pdf path must be a readable file.');
        }

        $manifest = $manifests->find(trim((string) $this->option('manifest')));
        if ($manifest === null || $manifest->selectors === []) {
            return $this->refuse('A known non-empty controlled --manifest is required.');
        }

        $expectedHash = trim((string) $this->option('sha256'));
        if (! preg_match('/^[0-9a-f]{64}$/', $expectedHash)) {
            return $this->refuse('--sha256 must be an exact lowercase SHA-256.');
        }
        if (! hash_equals($manifest->expectedSha256, $expectedHash)) {
            return $this->refuse('The supplied SHA-256 does not match the controlled manifest.');
        }
        $actualHash = hash_file('sha256', $pdfPath);
        if (! is_string($actualHash) || ! hash_equals($expectedHash, $actualHash)) {
            return $this->refuse('The source PDF SHA-256 does not match --sha256.');
        }

        $requestedPages = [];
        foreach ($manifest->selectors as $selector) {
            array_push($requestedPages, ...$selector->physicalPages);
        }
        $pageText = $acquirer->acquire($pdfPath, array_values(array_unique($requestedPages)));

        $prepared = [];
        foreach ($manifest->selectors as $selector) {
            $chunks = [];
            foreach ($selector->physicalPages as $pageNumber) {
                if (! array_key_exists($pageNumber, $pageText)) {
                    return $this->refuse("Source acquisition omitted requested physical page {$pageNumber}.");
                }
                $chunks[] = new SourceTextChunk($normalizer->normalize($pageText[$pageNumber]), $pageNumber);
            }

            $parsed = $parser->parseChunks($chunks);
            $sectionMatches = array_values(array_filter(
                $parsed->entries,
                fn (ParsedOfficialErrorEntry $entry): bool => $entry->sectionNumber === $selector->sectionNumber,
            ));
            if (count($sectionMatches) !== 1) {
                return $this->refuse("Requested section {$selector->sectionNumber} was not found exactly once.");
            }

            $entry = $sectionMatches[0];
            if ($entry->code !== $selector->code || $entry->variantKey !== $selector->variantKey) {
                return $this->refuse("Parsed identity differs from requested section {$selector->sectionNumber}.");
            }
            $prepared[] = $entry;
        }

        if ($dryRun) {
            $results = array_map(
                fn (ParsedOfficialErrorEntry $entry) => $ingestion->plan($document, $entry, OfficialKnowledgeIngestionPolicy::PASS_ONLY),
                $prepared,
            );
        } else {
            $unsafe = collect($prepared)->first(fn (ParsedOfficialErrorEntry $entry): bool => $entry->outcome !== ParserOutcome::PASS);
            if ($unsafe !== null) {
                return $this->refuse("Section {$unsafe->sectionNumber} has parser outcome {$unsafe->outcome->value}; PASS_ONLY is required. Zero official entries were persisted.");
            }
            $batch = $ingestion->ingestBatch($document, $prepared, OfficialKnowledgeIngestionPolicy::PASS_ONLY);
            if (! $batch->persisted) {
                return $this->refuse('The prepared batch was rejected; zero official entries were persisted.');
            }
            $results = $batch->results;
        }

        foreach ($prepared as $index => $entry) {
            $this->line(implode(' | ', [
                $entry->code,
                $entry->sectionNumber,
                $entry->variantKey,
                $entry->outcome->value,
                $results[$index]->action->value,
                'pages='.$entry->sourcePageStart.'-'.$entry->sourcePageEnd,
                'parts='.count($entry->parts),
                'steps='.count($entry->steps),
                'references='.count($entry->references),
                'warning='.($entry->warning === null ? 'NO' : 'YES'),
                'dipsw='.($entry->isolationDipsw === null ? 'NO' : 'YES'),
                'detached='.($entry->detachedControl === null ? 'NO' : 'YES'),
                'diagnostics='.($entry->diagnostics === []
                    ? 'NONE'
                    : implode(',', array_map(fn ($diagnostic): string => $diagnostic->code->value, $entry->diagnostics))),
            ]));
        }

        $counts = array_count_values(array_map(fn ($result): string => $result->action->value, $results));
        $outcomes = array_count_values(array_map(fn (ParsedOfficialErrorEntry $entry): string => $entry->outcome->value, $prepared));
        $this->line('OFFICIAL_INGESTION_MODE='.($dryRun ? 'DRY_RUN' : 'APPLY'));
        $this->line('OFFICIAL_INGESTION_MANIFEST='.$manifest->name);
        $this->line('OFFICIAL_INGESTION_SELECTED='.count($prepared));
        foreach (ParserOutcome::cases() as $outcome) {
            $this->line('OFFICIAL_INGESTION_'.$outcome->value.'='.($outcomes[$outcome->value] ?? 0));
        }
        foreach (OfficialKnowledgeIngestionAction::cases() as $action) {
            $this->line('OFFICIAL_INGESTION_'.$action->value.'='.($counts[$action->value] ?? 0));
        }
        $this->line('OFFICIAL_INGESTION_TRANSACTION='.($dryRun ? 'NOT_STARTED' : 'COMMITTED'));

        return self::SUCCESS;
    }

    private function refuse(string $reason): int
    {
        $this->error('OFFICIAL_INGESTION_REFUSED='.$reason);

        return self::FAILURE;
    }
}
