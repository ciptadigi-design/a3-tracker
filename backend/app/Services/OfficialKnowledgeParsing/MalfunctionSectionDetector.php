<?php

namespace App\Services\OfficialKnowledgeParsing;

final class MalfunctionSectionDetector
{
    /** A dotted manual section number followed by any four-character manufacturer C-code family. */
    private const HEADING_PATTERN = '/^(?<section>\d+(?:\.\d+)+)[ \t]+(?<code>C-[A-Z0-9]{4})[ \t]*\*?(?:[ \t]*\((?<applicability>[^\r\n)]+)\))?[ \t]*$/imu';

    public function detect(string $rawText): SemanticSectionDetectionResult
    {
        return $this->detectChunks([new SourceTextChunk($rawText)]);
    }

    /** @param list<SourceTextChunk> $chunks */
    public function detectChunks(array $chunks): SemanticSectionDetectionResult
    {
        [$text, $pageRanges] = $this->combineChunks($chunks);
        preg_match_all(self::HEADING_PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        if ($matches === []) {
            return new SemanticSectionDetectionResult([], [new ParserDiagnostic(
                ParserDiagnosticCode::MISSING_CODE,
                ParserDiagnosticSeverity::ERROR,
                'No valid malfunction-section heading was found.',
            )]);
        }

        $sections = [];
        foreach ($matches as $index => $match) {
            $start = $match[0][1];
            $end = $matches[$index + 1][0][1] ?? strlen($text);
            $rawSection = substr($text, $start, $end - $start);
            $lastContentOffset = $start + max(0, strlen(rtrim($rawSection)) - 1);

            $diagnostics = [];
            if (! isset($matches[$index + 1]) && ! $this->hasVerifiedFinalBoundary($chunks)) {
                $diagnostics[] = new ParserDiagnostic(
                    ParserDiagnosticCode::UNTERMINATED_SECTION,
                    ParserDiagnosticSeverity::ERROR,
                    'The final semantic section reached end-of-input without a verified following boundary.',
                );
            }

            $sections[] = new SemanticMalfunctionSection(
                strtoupper(trim($match['code'][0])),
                trim($match['section'][0]),
                isset($match['applicability']) && $match['applicability'][1] >= 0
                    ? trim($match['applicability'][0])
                    : null,
                $this->pageAt($start, $pageRanges),
                $this->pageAt($lastContentOffset, $pageRanges),
                $rawSection,
                $diagnostics,
            );
        }

        return new SemanticSectionDetectionResult($sections);
    }

    /** @param list<SourceTextChunk> $chunks */
    private function hasVerifiedFinalBoundary(array $chunks): bool
    {
        if ($chunks === []) {
            return false;
        }

        return $chunks[array_key_last($chunks)]->endsAtVerifiedSectionBoundary;
    }

    /**
     * Page chunks are joined with one newline only when the preceding chunk does not already end
     * in a line break. That deterministic join becomes part of the exact semantic source text.
     *
     * @param  list<SourceTextChunk>  $chunks
     * @return array{string, list<array{start: int, end: int, page: int|null}>}
     */
    private function combineChunks(array $chunks): array
    {
        $text = '';
        $ranges = [];

        foreach ($chunks as $index => $chunk) {
            if ($index > 0 && $text !== '' && ! str_ends_with($text, "\n") && ! str_ends_with($text, "\r")) {
                $text .= "\n";
            }

            $start = strlen($text);
            $text .= $chunk->text;
            $ranges[] = ['start' => $start, 'end' => strlen($text), 'page' => $chunk->pageNumber];
        }

        return [$text, $ranges];
    }

    /** @param list<array{start: int, end: int, page: int|null}> $ranges */
    private function pageAt(int $offset, array $ranges): ?int
    {
        foreach ($ranges as $range) {
            if ($offset >= $range['start'] && $offset < $range['end']) {
                return $range['page'];
            }
        }

        return null;
    }
}
