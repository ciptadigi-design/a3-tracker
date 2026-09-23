<?php

namespace App\Services\OfficialKnowledgeParsing;

final readonly class ParsedOfficialErrorStep
{
    /** @param list<ParsedOfficialReference> $references */
    public function __construct(
        public int $number,
        public string $instruction,
        public array $references = [],
    ) {}
}
