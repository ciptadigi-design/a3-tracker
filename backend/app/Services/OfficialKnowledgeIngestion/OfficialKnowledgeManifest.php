<?php

namespace App\Services\OfficialKnowledgeIngestion;

final readonly class OfficialKnowledgeManifest
{
    /** @param list<OfficialKnowledgeSelector> $selectors */
    public function __construct(
        public string $name,
        public string $expectedSha256,
        public array $selectors,
    ) {}
}
