<?php

namespace App\Services\OfficialKnowledgeParsing;

final readonly class SemanticSectionDetectionResult
{
    /**
     * @param  list<SemanticMalfunctionSection>  $sections
     * @param  list<ParserDiagnostic>  $diagnostics
     */
    public function __construct(
        public array $sections,
        public array $diagnostics = [],
    ) {}
}
