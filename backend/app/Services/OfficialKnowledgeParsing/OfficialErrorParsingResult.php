<?php

namespace App\Services\OfficialKnowledgeParsing;

final readonly class OfficialErrorParsingResult
{
    /**
     * @param  list<ParsedOfficialErrorEntry>  $entries
     * @param  list<ParserDiagnostic>  $diagnostics
     */
    public function __construct(
        public array $entries,
        public array $diagnostics,
        public ParserOutcome $outcome,
    ) {}
}
