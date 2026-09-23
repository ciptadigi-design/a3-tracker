<?php

namespace App\Services\OfficialKnowledgeIngestion;

final readonly class OfficialKnowledgeBatchIngestionResult
{
    /** @param list<OfficialKnowledgeIngestionResult> $results */
    public function __construct(
        public bool $persisted,
        public array $results,
    ) {}
}
