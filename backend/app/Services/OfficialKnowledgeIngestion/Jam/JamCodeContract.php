<?php

namespace App\Services\OfficialKnowledgeIngestion\Jam;

/**
 * Reviewed, additive contract for the "1.1 Jam code list" of the bizhub PRESS C1070 v1.5 service
 * manual. The reviewed fixture holds identities, pages and per-entry digests only - no manufacturer
 * text - and is pinned by hash. Tests build their own instance with synthetic values.
 */
final readonly class JamCodeContract
{
    public const NAME = 'konica-c1070-v1.5-jam-code-list';

    public const VERSION = 'v1';

    public const PARSER_REVISION = 'jam-code-table-parser/v1';

    public const EXTRACTOR_REVISION = 'pypdf/6.14.2-positioned-runs';

    public const SOURCE_SHA256 = 'ae679f5bcd31a49826f9b2587ad4b9483be93950f246c1ea014a7dd0ddcb6ee4';

    public const FIRST_PAGE = 1310;

    public const LAST_PAGE = 1334;

    /** @param array<string, int> $expected @param list<array<string, mixed>> $inventory @param list<array<string, mixed>> $quarantine */
    public function __construct(
        public string $name,
        public string $sourceSha256,
        public int $firstPage,
        public int $lastPage,
        public string $datasetDigest,
        public array $expected,
        public array $inventory,
        public array $quarantine,
    ) {}

    public static function reviewed(): self
    {
        $path = resource_path('maintenance/konica-c1070-v1.5-jam-code-list.json');
        $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($fixture['schema'] ?? null) !== 'maintenance-reviewed-jam-code-list/v1'
            || ($fixture['contract'] ?? null) !== self::NAME
            || ($fixture['source_pdf_sha256'] ?? null) !== self::SOURCE_SHA256
            || ($fixture['parser_revision'] ?? null) !== self::PARSER_REVISION
            || ($fixture['extractor_revision'] ?? null) !== self::EXTRACTOR_REVISION
            || ! is_array($fixture['entries'] ?? null) || ! is_array($fixture['quarantine'] ?? null) || ! is_array($fixture['expected'] ?? null)) {
            throw new \RuntimeException('The reviewed jam-code fixture envelope is invalid.');
        }

        // The pinned hash of the dataset identity lives in the fixture and is re-derived from the PDF at run time.
        return new self(self::NAME, self::SOURCE_SHA256, self::FIRST_PAGE, self::LAST_PAGE, (string) $fixture['dataset_digest'], $fixture['expected'], $fixture['entries'], $fixture['quarantine']);
    }

    public function confirmation(): string
    {
        return 'APPLY-JAM-'.$this->expected['accepted'].'-'.substr($this->datasetDigest, 0, 12);
    }

    /** @return list<int> */
    public function pages(): array
    {
        return range($this->firstPage, $this->lastPage);
    }
}
