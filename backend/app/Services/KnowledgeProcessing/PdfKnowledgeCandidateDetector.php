<?php

namespace App\Services\KnowledgeProcessing;

/**
 * V1.6 - deterministic per-page candidate detection. No LLM/AI, no fabricated
 * confidence percentages: evidence is one of three explainable buckets
 * (HIGH/MEDIUM/LOW), each backed by a concrete, checkable rule.
 *
 * Real-document evidence (read-only sample of the 2639-page Konica
 * extraction, see docs/maintenance/V1.6_KNOWLEDGE_PROCESSING.md - patterns
 * only, no manual text retained) found:
 *  - "Cause" appears on 579 pages, "Action" on 70, "Troubleshooting" on 507,
 *    "Detection" on 395, "Remedy" on 0 - real section markers, but none of
 *    them present on every page, so their presence is genuine signal, not
 *    noise.
 *  - The table of contents lists codes as dotted-leader lines
 *    ("2.16.13 C-2451.....................") with no cause/action text
 *    anywhere nearby - a real, common false-positive shape this detector
 *    must down-rank rather than treat as a full troubleshooting entry.
 *
 * Deliberately conservative per Section E/R: `title` falls back to a plain
 * "Error Code C-XXXX" whenever the text immediately following a match does
 * not look like a real short heading (this is the common case - most
 * matches are TOC/index lines or mid-paragraph cross-references, not the
 * code's own definition heading). `description` is a short bounded excerpt
 * for human review, never treated as an authoritative summary.
 * operator_solution/technician_solution are intentionally never populated
 * here - splitting real cause text from real corrective-action text
 * reliably, for arbitrary real-world manual prose, is not something a fixed
 * regex can do without risking fabrication; that stays a human review step
 * (MaintenanceKnowledgePublishService already requires APPROVED + human
 * publish action either way).
 */
final class PdfKnowledgeCandidateDetector
{
    /** Characters of context kept on each side of a match - bounded, never a whole page. */
    private const CONTEXT_RADIUS = 220;

    /** How close to the end of a page a match must be to even consider page-spanning continuation. */
    private const NEAR_PAGE_END_CHARS = 200;

    private const STRONG_MARKERS = ['cause', 'action', 'countermeasure', 'remedy'];

    private const WEAK_MARKERS = ['troubleshooting', 'detection'];

    /**
     * @return list<array{code: string, normalized_code: string, title: string, description: string, evidence: string, spans_next_page: bool}>
     */
    public static function detectOnPage(string $pageText, ?string $nextPageText): array
    {
        $candidates = [];
        foreach (ErrorCodeNormalizer::detectCandidates($pageText) as $match) {
            $matchLength = strlen($match['code']);
            $context = self::boundedContext($pageText, $match['offset'], $matchLength);
            $evidence = self::classifyEvidence($pageText, $match['offset'], $matchLength);
            $nearEnd = ($match['offset'] + $matchLength) > (strlen($pageText) - self::NEAR_PAGE_END_CHARS);
            $spansNextPage = $nearEnd && $nextPageText !== null && self::likelyContinuation($nextPageText);

            $candidates[] = [
                'code' => $match['code'],
                'normalized_code' => $match['normalized_code'],
                'title' => self::deriveTitle($pageText, $match['offset'], $matchLength, $match['normalized_code']),
                'description' => $context,
                'evidence' => $evidence,
                'spans_next_page' => $spansNextPage,
            ];
        }

        return $candidates;
    }

    private static function boundedContext(string $text, int $offset, int $matchLength): string
    {
        $start = max(0, $offset - self::CONTEXT_RADIUS);
        $length = min(strlen($text), $offset + $matchLength + self::CONTEXT_RADIUS) - $start;

        return trim(substr($text, $start, $length));
    }

    /**
     * HIGH: a strong section marker (Cause/Action/Countermeasure/Remedy)
     * appears within the same bounded context window as the match - the
     * match is very likely inside a real troubleshooting entry, not a list.
     * MEDIUM: only a weak marker (Troubleshooting/Detection) is present
     * nearby, or a strong marker exists elsewhere on the page but not close
     * to this specific match.
     * LOW: no marker nearby at all, or the match sits inside an obvious
     * table-of-contents/index dotted-leader line.
     */
    private static function classifyEvidence(string $pageText, int $offset, int $matchLength): string
    {
        $window = strtolower(self::boundedContext($pageText, $offset, $matchLength));

        $tocLeaderNearby = preg_match('/\.{5,}/', $window) === 1;
        if ($tocLeaderNearby && ! self::containsAny($window, self::STRONG_MARKERS)) {
            return 'LOW';
        }

        if (self::containsAny($window, self::STRONG_MARKERS)) {
            return 'HIGH';
        }

        $pageLower = strtolower($pageText);
        if (self::containsAny($window, self::WEAK_MARKERS) || self::containsAny($pageLower, self::STRONG_MARKERS)) {
            return 'MEDIUM';
        }

        return 'LOW';
    }

    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A code found within the closing stretch of a page, where the very
     * start of the next page does not itself open with a fresh code, is
     * treated as continuing onto that one adjacent page - never further.
     * Deliberately simple: this is a heuristic for bounding context
     * collection (Section F), not a claim about document structure.
     */
    private static function likelyContinuation(string $nextPageText): bool
    {
        $leadingSlice = substr(ltrim($nextPageText), 0, 100);

        return ErrorCodeNormalizer::detectCandidates($leadingSlice) === [];
    }

    /**
     * The text immediately following a match, up to the next newline, is
     * only trusted as a real title when it contains a genuine short run of
     * letters and is not itself dominated by TOC dot-leaders or bare
     * numbers - otherwise falls back to a plain, honest placeholder rather
     * than fabricating a heading from noise.
     */
    private static function deriveTitle(string $pageText, int $offset, int $matchLength, string $normalizedCode): string
    {
        $fallback = 'Error Code '.$normalizedCode;
        $afterMatch = substr($pageText, $offset + $matchLength, 120);
        $newlinePos = strcspn($afterMatch, "\n\r");
        $candidate = trim(substr($afterMatch, 0, $newlinePos), " \t.-:");

        if ($candidate === '') {
            return $fallback;
        }
        if (! preg_match('/[A-Za-z]{3,}/', $candidate)) {
            return $fallback; // no real word-like content - likely dots/numbers/whitespace only
        }
        $dotCount = substr_count($candidate, '.');
        if ($dotCount > 0 && $dotCount >= (strlen($candidate) * 0.3)) {
            return $fallback; // dominated by TOC dot-leaders
        }
        if (strlen($candidate) > 100) {
            $candidate = rtrim(substr($candidate, 0, 100));
        }

        return $candidate;
    }
}
