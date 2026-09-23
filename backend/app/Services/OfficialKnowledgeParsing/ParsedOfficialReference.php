<?php

namespace App\Services\OfficialKnowledgeParsing;

final readonly class ParsedOfficialReference
{
    public function __construct(
        public string $type,
        public string $value,
        public ?int $stepNumber = null,
        public ?int $pageNumber = null,
        public ?string $sectionNumber = null,
    ) {}
}
