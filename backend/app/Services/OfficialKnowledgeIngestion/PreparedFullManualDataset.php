<?php

namespace App\Services\OfficialKnowledgeIngestion;

use App\Services\OfficialKnowledgeParsing\ParsedOfficialErrorEntry;

final readonly class PreparedFullManualDataset
{
    /**
     * @param  list<ParsedOfficialErrorEntry>  $entries
     * @param  list<ParsedOfficialErrorEntry>  $eligibleEntries
     * @param  array<string, int>  $metrics
     * @param  list<array<string, mixed>>  $excluded
     * @param  list<array<string, mixed>>  $reviewedInventory
     */
    public function __construct(
        public array $entries,
        public array $eligibleEntries,
        public array $metrics,
        public array $excluded,
        public string $datasetDigest,
        public array $reviewedInventory,
    ) {}
}
