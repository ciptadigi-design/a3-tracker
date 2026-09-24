<?php

namespace App\Services\OfficialKnowledgeIngestion;

use App\Services\OfficialKnowledgeParsing\ParserDiagnosticCode;
use App\Services\OfficialKnowledgeParsing\ParserOutcome;
use App\Services\OfficialKnowledgeParsing\PdfPageTextNormalizer;
use App\Services\OfficialKnowledgeParsing\SemanticErrorCodeParser;
use App\Services\OfficialKnowledgeParsing\SourceTextChunk;
use RuntimeException;

final class FullManualDatasetPreparer
{
    public function __construct(
        private readonly OfficialPdfTextAcquirer $acquirer,
        private readonly PdfPageTextNormalizer $normalizer,
        private readonly SemanticErrorCodeParser $parser,
        private readonly OfficialKnowledgeCanonicalizer $canonicalizer,
    ) {}

    public function prepare(ResolvedOfficialDocumentSource $source, ReviewedFullManualContract $contract): PreparedFullManualDataset
    {
        $pages = $contract->pages();
        $pageText = $this->acquirer->acquire($source->snapshotPath, $pages);
        $chunks = [];
        foreach ($pages as $page) {
            if (! isset($pageText[$page])) {
                throw new RuntimeException("Source acquisition omitted reviewed physical page {$page}.");
            }
            $chunks[] = new SourceTextChunk(
                $this->normalizer->normalize($pageText[$page]),
                $page,
                $page === $contract->lastPage(),
            );
        }

        $parsed = $this->parser->parseChunks($chunks);
        $entries = $parsed->entries;
        $eligible = array_values(array_filter($entries, static fn ($entry): bool => $entry->outcome === ParserOutcome::PASS));
        $outcomes = array_count_values(array_map(static fn ($entry): string => $entry->outcome->value, $entries));
        $identities = [];
        foreach ($entries as $entry) {
            $identity = strtoupper(trim($entry->code))."\0".$entry->variantKey;
            $identities[$identity] = ($identities[$identity] ?? 0) + 1;
        }

        $metrics = [
            'discovered' => count($entries),
            'pass' => $outcomes['PASS'] ?? 0,
            'warn' => $outcomes['WARN'] ?? 0,
            'fail' => $outcomes['FAIL'] ?? 0,
            'eligible' => count($eligible),
            'distinct_discovered_codes' => count(array_unique(array_column($entries, 'code'))),
            'distinct_eligible_codes' => count(array_unique(array_column($eligible, 'code'))),
            'identity_collisions' => count(array_filter($identities, static fn (int $count): bool => $count > 1)),
            'unresolved_applicability' => count(array_filter($entries, static fn ($entry): bool => str_starts_with($entry->variantKey, 'UNRESOLVED_'))),
            'unverified_boundaries' => count(array_filter($entries, static fn ($entry): bool => in_array(ParserDiagnosticCode::UNTERMINATED_SECTION, array_column($entry->diagnostics, 'code'), true))),
            'applicabilities' => array_sum(array_map(static fn ($entry): int => count($entry->applicabilities), $eligible)),
            'parts' => array_sum(array_map(static fn ($entry): int => count($entry->parts), $eligible)),
            'steps' => array_sum(array_map(static fn ($entry): int => count($entry->steps), $eligible)),
            'references' => array_sum(array_map(static fn ($entry): int => count($entry->references), $eligible)),
            'warnings' => count(array_filter($eligible, static fn ($entry): bool => $entry->warning !== null)),
            'dipsw' => count(array_filter($eligible, fn ($entry): bool => $this->isDipswBearing($entry))),
            'detached_control' => count(array_filter($eligible, static fn ($entry): bool => $entry->detachedControl !== null)),
        ];

        $excluded = array_map(static fn ($entry): array => [
            'section' => $entry->sectionNumber,
            'code' => $entry->code,
            'variant_key' => $entry->variantKey,
            'outcome' => $entry->outcome->value,
            'diagnostics' => array_map(static fn ($diagnostic): array => [
                'code' => $diagnostic->code->value,
                'severity' => $diagnostic->severity->value,
                'message' => $diagnostic->message,
            ], $entry->diagnostics),
            'source_page_start' => $entry->sourcePageStart,
            'source_page_end' => $entry->sourcePageEnd,
            'source_hash' => $entry->sourceHash,
        ], array_values(array_filter($entries, static fn ($entry): bool => $entry->outcome !== ParserOutcome::PASS)));

        $reviewedInventory = array_map($this->canonicalizer->reviewedInventoryEntry(...), $entries);
        usort($reviewedInventory, static function (array $a, array $b): int {
            return strcmp(
                $a['code']."\0".$a['variant_key']."\0".($a['section'] ?? ''),
                $b['code']."\0".$b['variant_key']."\0".($b['section'] ?? ''),
            );
        });

        return new PreparedFullManualDataset(
            $entries,
            $eligible,
            $metrics,
            $excluded,
            $this->canonicalizer->datasetDigest($eligible, $source->sha256),
            $reviewedInventory,
        );
    }

    private function isDipswBearing($entry): bool
    {
        $values = [
            $entry->classification, $entry->cause, $entry->alertMeasure, $entry->correction,
            $entry->warning, $entry->note, $entry->isolationDipsw, $entry->detachedControl,
            ...array_map(static fn ($step): string => $step->instruction, $entry->steps),
        ];

        return preg_match('/DIPSW/iu', implode("\n", array_filter($values, static fn ($value): bool => $value !== null))) === 1;
    }
}
