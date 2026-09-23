<?php

namespace App\Services\OfficialKnowledgeParsing;

final class SemanticErrorCodeParser
{
    public function __construct(
        private readonly MalfunctionSectionDetector $sectionDetector = new MalfunctionSectionDetector,
        private readonly OfficialErrorFieldParser $fieldParser = new OfficialErrorFieldParser,
    ) {}

    public function parse(string $rawText): OfficialErrorParsingResult
    {
        return $this->parseDetected($this->sectionDetector->detect($rawText));
    }

    /** @param list<SourceTextChunk> $chunks */
    public function parseChunks(array $chunks): OfficialErrorParsingResult
    {
        return $this->parseDetected($this->sectionDetector->detectChunks($chunks));
    }

    private function parseDetected(SemanticSectionDetectionResult $detected): OfficialErrorParsingResult
    {
        $entries = array_map($this->fieldParser->parse(...), $detected->sections);
        $outcome = ParserOutcome::PASS;

        if ($entries === [] || $this->containsOutcome($entries, ParserOutcome::FAIL)) {
            $outcome = ParserOutcome::FAIL;
        } elseif ($detected->diagnostics !== [] || $this->containsOutcome($entries, ParserOutcome::WARN)) {
            $outcome = ParserOutcome::WARN;
        }

        return new OfficialErrorParsingResult($entries, $detected->diagnostics, $outcome);
    }

    /** @param list<ParsedOfficialErrorEntry> $entries */
    private function containsOutcome(array $entries, ParserOutcome $outcome): bool
    {
        foreach ($entries as $entry) {
            if ($entry->outcome === $outcome) {
                return true;
            }
        }

        return false;
    }
}
