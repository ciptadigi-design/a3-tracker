<?php

namespace App\Services\OfficialKnowledgeIngestion\Jam;

use App\Models\MaintenanceOfficialErrorEntry;
use App\Services\OfficialKnowledgeIngestion\OfficialKnowledgeCanonicalizer;
use App\Services\OfficialKnowledgeIngestion\ResolvedOfficialDocumentSource;
use App\Services\OfficialKnowledgeParsing\ParsedOfficialErrorEntry;
use App\Services\OfficialKnowledgeParsing\ParsedOfficialErrorStep;
use App\Services\OfficialKnowledgeParsing\ParserOutcome;

final class JamCodeDatasetPreparer
{
    public function __construct(
        private readonly OfficialPdfSegmentAcquirer $acquirer,
        private readonly JamCodeTableParser $parser,
        private readonly OfficialKnowledgeCanonicalizer $canonicalizer,
    ) {}

    /** @return array{entries: list<ParsedOfficialErrorEntry>, metrics: array<string, int>, digest: string, inventory: list<array<string, mixed>>, quarantine: list<array<string, mixed>>} */
    public function prepare(ResolvedOfficialDocumentSource $source, JamCodeContract $contract): array
    {
        $pages = $this->acquirer->acquire($source->snapshotPath, $contract->pages());

        return $this->fromSegments($pages, $source->sha256);
    }

    /** @param array<int, list<array{0: float, 1: float, 2: string}>> $pages */
    public function fromSegments(array $pages, string $sourceSha256): array
    {
        $parsed = $this->parser->parse($pages);
        $entries = array_map($this->entry(...), $parsed->rows);
        $codes = array_unique(array_map(static fn (JamCodeRow $row): string => $row->code, $parsed->rows));
        $discoveredCodes = array_unique([...$codes, ...array_map(static fn (array $q): string => $q['code'], $parsed->quarantined)]);

        $inventory = array_map(fn (ParsedOfficialErrorEntry $entry): array => [
            'code' => $entry->code,
            'variant_key' => MaintenanceOfficialErrorEntry::normalizeVariantKey($entry->variantKey),
            'page_start' => $entry->sourcePageStart,
            'page_end' => $entry->sourcePageEnd,
            'entry_digest' => $this->canonicalizer->entryDigest($entry),
        ], $entries);
        usort($inventory, static fn (array $a, array $b): int => strcmp($a['code']."\0".$a['variant_key'], $b['code']."\0".$b['variant_key']));

        $quarantine = array_map(static fn (array $q): array => ['code' => $q['code'], 'page' => $q['page_start'], 'reason' => $q['reason']], $parsed->quarantined);

        return [
            'entries' => $entries,
            'metrics' => [
                'discovered' => $parsed->discovered,
                'accepted' => count($entries),
                'quarantined' => count($parsed->quarantined),
                'distinct_codes' => count($codes),
                'distinct_discovered_codes' => count($discoveredCodes),
                'applicabilities' => count($entries),
                'steps' => count($entries),
            ],
            'digest' => $this->canonicalizer->datasetDigest($entries, $sourceSha256),
            'inventory' => $inventory,
            'quarantine' => $quarantine,
        ];
    }

    private function entry(JamCodeRow $row): ParsedOfficialErrorEntry
    {
        $raw = implode("\n", array_filter([
            'Classification: '.$row->classification,
            'JAM Code: '.$row->code,
            'Cause: '.$row->cause,
            $row->resultingOperation !== null ? 'Resulting operation: '.$row->resultingOperation : null,
            'Correction: '.$row->correction,
        ]));

        return new ParsedOfficialErrorEntry(
            code: $row->code,
            variantKey: MaintenanceOfficialErrorEntry::normalizeVariantKey($row->classification),
            sectionNumber: JamCodeTableParser::SECTION,
            classification: 'Jam indication: '.$row->classification,
            cause: $row->cause,
            alertMeasure: null,
            correction: $row->correction,
            warning: null,
            note: $row->resultingOperation !== null ? 'Resulting operation: '.$row->resultingOperation : null,
            isolationDipsw: null,
            detachedControl: null,
            applicabilities: [$row->classification],
            parts: [],
            steps: [new ParsedOfficialErrorStep(1, $row->correction)],
            references: [],
            sourcePageStart: $row->pageStart,
            sourcePageEnd: $row->pageEnd,
            rawSourceText: $raw,
            sourceHash: hash('sha256', $raw),
            diagnostics: [],
            outcome: ParserOutcome::PASS,
        );
    }
}
