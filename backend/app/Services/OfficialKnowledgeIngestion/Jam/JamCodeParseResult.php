<?php

namespace App\Services\OfficialKnowledgeIngestion\Jam;

final readonly class JamCodeParseResult
{
    /**
     * @param  list<JamCodeRow>  $rows
     * @param  list<array{code: string, classification: string, page_start: int, page_end: int, reason: string}>  $quarantined
     */
    public function __construct(public array $rows, public array $quarantined, public int $discovered) {}
}
