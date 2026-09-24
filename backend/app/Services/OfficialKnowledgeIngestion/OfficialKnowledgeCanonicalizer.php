<?php

namespace App\Services\OfficialKnowledgeIngestion;

use App\Models\MaintenanceOfficialErrorEntry;
use App\Services\OfficialKnowledgeParsing\ParsedOfficialErrorEntry;
use JsonException;

final class OfficialKnowledgeCanonicalizer
{
    public const SCHEMA = 'maintenance-official-eligible-dataset/v1';

    /** @return array<string, mixed> */
    public function entry(ParsedOfficialErrorEntry $entry): array
    {
        $references = array_map(static fn ($reference): array => [
            'step_number' => $reference->stepNumber,
            'type' => $reference->type,
            'value' => $reference->value,
            'page_number' => $reference->pageNumber,
            'section_number' => $reference->sectionNumber,
        ], $entry->references);
        usort($references, fn (array $a, array $b): int => strcmp($this->json($a), $this->json($b)));

        $diagnostics = [];
        foreach ($entry->diagnostics as $diagnostic) {
            $context = $diagnostic->context;
            ksort($context, SORT_STRING);
            $diagnostics[] = [
                'code' => $diagnostic->code->value,
                'severity' => $diagnostic->severity->value,
                'message' => $diagnostic->message,
                'context' => $context,
            ];
        }
        usort($diagnostics, fn (array $a, array $b): int => strcmp($this->json($a), $this->json($b)));

        return [
            'section_number' => $entry->sectionNumber,
            'code' => strtoupper(trim($entry->code)),
            'variant_key' => MaintenanceOfficialErrorEntry::normalizeVariantKey($entry->variantKey),
            'source_page_start' => $entry->sourcePageStart,
            'source_page_end' => $entry->sourcePageEnd,
            'raw_source_hash' => $entry->sourceHash,
            'outcome' => $entry->outcome->value,
            'classification' => $entry->classification,
            'cause' => $entry->cause,
            'alert_measure' => $entry->alertMeasure,
            'correction' => $entry->correction,
            'warning' => $entry->warning,
            'note' => $entry->note,
            'isolation_dipsw' => $entry->isolationDipsw,
            'detached_control' => $entry->detachedControl,
            'applicabilities' => array_values($entry->applicabilities),
            'parts' => array_values($entry->parts),
            'steps' => array_map(static fn ($step): array => [
                'number' => $step->number,
                'instruction' => $step->instruction,
            ], $entry->steps),
            'references' => $references,
            'diagnostics' => $diagnostics,
        ];
    }

    public function entryDigest(ParsedOfficialErrorEntry $entry): string
    {
        return hash('sha256', $this->json($this->entry($entry)));
    }

    /** @return array<string, mixed> */
    public function reviewedInventoryEntry(ParsedOfficialErrorEntry $entry): array
    {
        $values = [
            $entry->classification, $entry->cause, $entry->alertMeasure, $entry->correction,
            $entry->warning, $entry->note, $entry->isolationDipsw, $entry->detachedControl,
            ...array_map(static fn ($step): string => $step->instruction, $entry->steps),
        ];

        return [
            'section' => $entry->sectionNumber,
            'code' => strtoupper(trim($entry->code)),
            'variant_key' => MaintenanceOfficialErrorEntry::normalizeVariantKey($entry->variantKey),
            'outcome' => $entry->outcome->value,
            'source_page_start' => $entry->sourcePageStart,
            'source_page_end' => $entry->sourcePageEnd,
            'source_hash' => $entry->sourceHash,
            'entry_digest' => $this->entryDigest($entry),
            'applicabilities' => count($entry->applicabilities),
            'parts' => count($entry->parts),
            'steps' => count($entry->steps),
            'references' => count($entry->references),
            'warning_bearing' => $entry->warning !== null,
            'dipsw_bearing' => preg_match('/DIPSW/iu', implode("\n", array_filter($values, static fn ($value): bool => $value !== null))) === 1,
            'detached_control_bearing' => $entry->detachedControl !== null,
            'diagnostics' => array_map(static fn ($diagnostic): string => $diagnostic->code->value, $entry->diagnostics),
        ];
    }

    /** @param list<ParsedOfficialErrorEntry> $entries */
    public function datasetDigest(array $entries, string $sourcePdfSha256): string
    {
        $canonical = array_map($this->entry(...), $entries);
        usort($canonical, static function (array $a, array $b): int {
            $left = $a['code']."\0".$a['variant_key']."\0".($a['section_number'] ?? '')."\0".$a['raw_source_hash'];
            $right = $b['code']."\0".$b['variant_key']."\0".($b['section_number'] ?? '')."\0".$b['raw_source_hash'];

            return strcmp($left, $right);
        });

        return hash('sha256', $this->json([
            'schema' => self::SCHEMA,
            'source_pdf_sha256' => $sourcePdfSha256,
            'entries' => $canonical,
        ]));
    }

    /** @param array<mixed> $value */
    private function json(array $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new \RuntimeException('Official knowledge canonical JSON encoding failed.', previous: $exception);
        }
    }
}
