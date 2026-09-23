<?php

namespace App\Services\OfficialKnowledgeIngestion;

use App\Models\MaintenanceOfficialErrorEntry;

final readonly class OfficialKnowledgeIngestionResult
{
    public function __construct(
        public OfficialKnowledgeIngestionAction $action,
        public ?MaintenanceOfficialErrorEntry $entry = null,
        public ?string $reason = null,
    ) {}
}
