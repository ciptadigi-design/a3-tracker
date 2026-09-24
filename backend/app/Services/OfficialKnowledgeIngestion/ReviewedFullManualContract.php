<?php

namespace App\Services\OfficialKnowledgeIngestion;

final readonly class ReviewedFullManualContract
{
    public const NAME = 'konica-c1070-v1.5-full-reviewed';

    public const VERSION = 'v1';

    public const SOURCE_SHA256 = 'ae679f5bcd31a49826f9b2587ad4b9483be93950f246c1ea014a7dd0ddcb6ee4';

    public const DATASET_DIGEST = 'e6ef17a74c8b9c92a26d03c9b856e1244d4f215c3db806adfb4fdaed52e05775';

    public const PARSER_REVISION = 'semantic-error-parser/v2.1-full-manual-1';

    public const EXTRACTOR_REVISION = 'pypdf/6.14.2-layout';

    public const CONFIRMATION = 'APPLY-625-e6ef17a74c8b';

    public const FIXTURE_SHA256 = '4253d6c2b46c2c47acae811ae868e29f16ad9c53f6e571394835c032ac1e5ba1';

    public function firstPage(): int
    {
        return 1412;
    }

    public function lastPage(): int
    {
        return 1670;
    }

    /** @return list<int> */
    public function pages(): array
    {
        return range($this->firstPage(), $this->lastPage());
    }

    /** @return array<string, int> */
    public function expected(): array
    {
        return [
            'discovered' => 628,
            'pass' => 625,
            'warn' => 1,
            'fail' => 2,
            'eligible' => 625,
            'distinct_discovered_codes' => 617,
            'distinct_eligible_codes' => 614,
            'identity_collisions' => 0,
            'unresolved_applicability' => 0,
            'unverified_boundaries' => 0,
            'applicabilities' => 636,
            'parts' => 2011,
            'steps' => 3877,
            'references' => 1538,
            'warnings' => 53,
            'dipsw' => 242,
            'detached_control' => 204,
        ];
    }

    /** @return list<array{section: string, code: string, variant_key: string, outcome: string}> */
    public function exclusions(): array
    {
        return [
            ['section' => '2.14.8', 'code' => 'C-1547', 'variant_key' => 'PB', 'outcome' => 'WARN'],
            ['section' => '2.25.17', 'code' => 'C-C131', 'variant_key' => 'MAIN_BODY', 'outcome' => 'FAIL'],
            ['section' => '2.26.21', 'code' => 'C-D0F8', 'variant_key' => 'MAIN_BODY', 'outcome' => 'FAIL'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function reviewedInventory(): array
    {
        $path = resource_path('maintenance/konica-c1070-v1.5-full-reviewed.json');
        $hash = is_file($path) ? hash_file('sha256', $path) : false;
        if (! is_string($hash) || ! hash_equals(self::FIXTURE_SHA256, $hash)) {
            throw new \RuntimeException('The reviewed full-manual contract fixture is missing or has changed.');
        }
        $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($fixture['schema'] ?? null) !== 'maintenance-reviewed-full-manual-contract/v1'
            || ($fixture['contract'] ?? null) !== self::NAME
            || ($fixture['source_pdf_sha256'] ?? null) !== self::SOURCE_SHA256
            || ($fixture['dataset_digest'] ?? null) !== self::DATASET_DIGEST
            || ($fixture['parser_revision'] ?? null) !== self::PARSER_REVISION
            || ($fixture['extractor_revision'] ?? null) !== self::EXTRACTOR_REVISION
            || ($fixture['first_page'] ?? null) !== $this->firstPage()
            || ($fixture['last_page'] ?? null) !== $this->lastPage()
            || ($fixture['expected'] ?? null) !== $this->expected()
            || ! is_array($fixture['entries'] ?? null)) {
            throw new \RuntimeException('The reviewed full-manual contract fixture envelope is invalid.');
        }

        return $fixture['entries'];
    }
}
