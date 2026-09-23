<?php

namespace App\Services\OfficialKnowledgeIngestion;

interface OfficialKnowledgeManifestProvider
{
    public function find(string $name): ?OfficialKnowledgeManifest;
}
