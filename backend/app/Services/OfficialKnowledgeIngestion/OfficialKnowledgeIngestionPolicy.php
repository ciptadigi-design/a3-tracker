<?php

namespace App\Services\OfficialKnowledgeIngestion;

enum OfficialKnowledgeIngestionPolicy: string
{
    case PASS_ONLY = 'PASS_ONLY';
    case PASS_OR_WARN = 'PASS_OR_WARN';
}
