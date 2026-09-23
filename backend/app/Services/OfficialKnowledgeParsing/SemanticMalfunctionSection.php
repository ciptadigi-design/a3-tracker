<?php

namespace App\Services\OfficialKnowledgeParsing;

final readonly class SemanticMalfunctionSection
{
    /** @param list<ParserDiagnostic> $diagnostics */
    public function __construct(
        public string $code,
        public ?string $sectionNumber,
        public ?string $headingApplicability,
        public ?int $sourcePageStart,
        public ?int $sourcePageEnd,
        public string $rawSourceText,
        public array $diagnostics = [],
    ) {}
}
