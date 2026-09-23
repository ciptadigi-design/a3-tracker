<?php

namespace App\Services\OfficialKnowledgeIngestion;

enum OfficialKnowledgeIngestionAction: string
{
    case CREATED = 'CREATED';
    case UNCHANGED = 'UNCHANGED';
    case UPDATED = 'UPDATED';
    case REJECTED = 'REJECTED';
}
