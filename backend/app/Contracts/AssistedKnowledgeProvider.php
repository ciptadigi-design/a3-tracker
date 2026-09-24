<?php

namespace App\Contracts;

use App\Models\MaintenanceOfficialErrorEntry;

interface AssistedKnowledgeProvider
{
    public function generate(MaintenanceOfficialErrorEntry $entry, string $language): array;

    public function metadata(): array;
}
