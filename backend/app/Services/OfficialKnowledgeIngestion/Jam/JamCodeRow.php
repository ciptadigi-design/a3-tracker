<?php

namespace App\Services\OfficialKnowledgeIngestion\Jam;

final readonly class JamCodeRow
{
    public function __construct(
        public string $code,
        public string $classification,
        public string $cause,
        public ?string $resultingOperation,
        public string $correction,
        public int $pageStart,
        public int $pageEnd,
    ) {}
}
