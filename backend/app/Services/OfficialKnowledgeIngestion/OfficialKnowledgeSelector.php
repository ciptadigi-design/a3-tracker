<?php

namespace App\Services\OfficialKnowledgeIngestion;

final readonly class OfficialKnowledgeSelector
{
    /** @param list<int> $physicalPages */
    public function __construct(
        public string $sectionNumber,
        public string $code,
        public string $variantKey,
        public array $physicalPages,
    ) {}
}
