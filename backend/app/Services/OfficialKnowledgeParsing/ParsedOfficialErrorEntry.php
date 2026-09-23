<?php

namespace App\Services\OfficialKnowledgeParsing;

final readonly class ParsedOfficialErrorEntry
{
    /**
     * @param  list<string>  $applicabilities
     * @param  list<string>  $parts
     * @param  list<ParsedOfficialErrorStep>  $steps
     * @param  list<ParsedOfficialReference>  $references
     * @param  list<ParserDiagnostic>  $diagnostics
     */
    public function __construct(
        public string $code,
        public string $variantKey,
        public ?string $sectionNumber,
        public ?string $classification,
        public ?string $cause,
        public ?string $alertMeasure,
        public ?string $correction,
        public ?string $warning,
        public ?string $note,
        public ?string $isolationDipsw,
        public ?string $detachedControl,
        public array $applicabilities,
        public array $parts,
        public array $steps,
        public array $references,
        public ?int $sourcePageStart,
        public ?int $sourcePageEnd,
        public string $rawSourceText,
        public string $sourceHash,
        public array $diagnostics,
        public ParserOutcome $outcome,
    ) {}
}
