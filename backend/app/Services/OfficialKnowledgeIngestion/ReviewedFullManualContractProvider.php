<?php

namespace App\Services\OfficialKnowledgeIngestion;

final class ReviewedFullManualContractProvider
{
    public function find(string $name): ?ReviewedFullManualContract
    {
        return $name === ReviewedFullManualContract::NAME ? new ReviewedFullManualContract : null;
    }
}
