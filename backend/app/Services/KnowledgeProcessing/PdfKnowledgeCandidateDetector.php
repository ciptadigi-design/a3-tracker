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
 *
 * V1.6.1 - UTF-8-safe bounded slicing. The real Production run (2639-page
 * Konica document) failed with a MySQL "Incorrect string value: '\xE2'" on
 * `description` - not a charset/schema problem (the column is already
 * utf8mb4) but a real bug here: boundedContext()/deriveTitle() sliced with
 * byte-oriented `substr()` at an arbitrary fixed radius (+/-220 bytes),
 * which real manual text's legitimate multi-byte Unicode punctuation
 * (curly quotes, em/en dashes, bullets, degree signs, accented Latin, etc.)
 * can land in the middle of, producing truncated/invalid UTF-8 that MySQL
 * correctly rejected. Fixed by expressing every arbitrary radius/length in
 * CHARACTERS via mb_*, never bytes - `preg_match`'s PREG_OFFSET_CAPTURE
 * offsets are always byte offsets even for a plain (non-`/u`) pattern, but
 * they are safe to convert to a character offset via a plain `substr()`
 * prefix cut specifically because ErrorCodeNormalizer's pattern only ever
 * matches pure ASCII (`C`, `-`, digits, whitespace) - an ASCII byte can
 * never be a UTF-8 continuation byte, so cutting a valid UTF-8 string right
 * before one is always safe, regardless of what precedes it.
 *
 * Deliberately distinct from "already-corrupt extracted text": toValidUtf8()
 * only ever touches a string that fails mb_check_encoding() to begin with
 * (never rewrites text that trusted extraction has always shown to be
 * valid UTF-8) - a slicing bug and a corrupt-input problem are two
 * different failure modes and are never conflated here.
 */
final class PdfKnowledgeCandidateDetector
{
    /** Characters (never bytes) of context kept on each side of a match - bounded, never a whole page. */
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
        $safePageText = self::toValidUtf8($pageText);
        $candidates = [];
        foreach (ErrorCodeNormalizer::detectCandidates($safePageText) as $match) {
            $matchByteLength = strlen($match['code']);
            $charOffset = self::byteOffsetToCharOffset($safePageText, $match['offset']);
            // The match itself is pure ASCII (see ErrorCodeNormalizer::DETECT_PATTERN),
            // so its byte length equals its character length.
            $matchCharLength = $matchByteLength;

            $context = self::boundedContext($safePageText, $charOffset, $matchCharLength);
            $evidence = self::classifyEvidence($safePageText, $charOffset, $matchCharLength);
            $totalChars = mb_strlen($safePageText, 'UTF-8');
            $nearEnd = ($charOffset + $matchCharLength) > ($totalChars - self::NEAR_PAGE_END_CHARS);
            $spansNextPage = $nearEnd && $nextPageText !== null && self::likelyContinuation($nextPageText);

            $candidates[] = [
                'code' => $match['code'],
                'normalized_code' => $match['normalized_code'],
                'title' => self::deriveTitle($safePageText, $charOffset, $matchCharLength, $match['normalized_code']),
                'description' => $context,
                'evidence' => $evidence,
                'spans_next_page' => $spansNextPage,
            ];
        }

        return $candidates;
    }

    /**
     * Only ever rewrites input that is NOT already valid UTF-8 - a distinct,
     * separately-handled case from a valid string later sliced incorrectly
     * (see class docblock). Strips invalid byte sequences rather than
     * letting them ever reach a slicing operation or MySQL.
     */
    private static function toValidUtf8(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $text);

        return $fixed !== false ? $fixed : '';
    }

    /**
     * Converts a byte offset (as returned by preg_match_all's
     * PREG_OFFSET_CAPTURE, always byte-based) into a character offset.
     * Safe only because every caller passes an offset that points at the
     * start of an ASCII match - substr() cutting a valid UTF-8 string right
     * before an ASCII byte can never split a multi-byte character, so the
     * prefix this counts is always a complete, valid string.
     */
    private static function byteOffsetToCharOffset(string $validUtf8Text, int $byteOffset): int
    {
        return mb_strlen(substr($validUtf8Text, 0, $byteOffset), 'UTF-8');
    }

    /** $charOffset/$matchCharLength are character positions, never bytes - see class docblock. */
    private static function boundedContext(string $validUtf8Text, int $charOffset, int $matchCharLength): string
    {
        $totalChars = mb_strlen($validUtf8Text, 'UTF-8');
        $start = max(0, $charOffset - self::CONTEXT_RADIUS);
        $end = min($totalChars, $charOffset + $matchCharLength + self::CONTEXT_RADIUS);

        return trim(mb_substr($validUtf8Text, $start, $end - $start, 'UTF-8'));
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
    private static function classifyEvidence(string $validUtf8Text, int $charOffset, int $matchCharLength): string
    {
        // strtolower() (not mb_strtolower()) is intentional and safe here: it only
        // ever rewrites ASCII A-Z bytes, leaving multi-byte UTF-8 sequences
        // byte-for-byte untouched (their bytes are all >= 0x80) - proper Unicode
        // case-folding is unnecessary since only ASCII marker words are searched for.
        $window = strtolower(self::boundedContext($validUtf8Text, $charOffset, $matchCharLength));

        $tocLeaderNearby = preg_match('/\.{5,}/', $window) === 1;
        if ($tocLeaderNearby && ! self::containsAny($window, self::STRONG_MARKERS)) {
            return 'LOW';
        }

        if (self::containsAny($window, self::STRONG_MARKERS)) {
            return 'HIGH';
        }

        $pageLower = strtolower($validUtf8Text);
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
        $safeText = self::toValidUtf8(ltrim($nextPageText));
        $leadingSlice = mb_substr($safeText, 0, 100, 'UTF-8');

        return ErrorCodeNormalizer::detectCandidates($leadingSlice) === [];
    }

    /**
     * The text immediately following a match, up to the next newline, is
     * only trusted as a real title when it contains a genuine short run of
     * letters and is not itself dominated by TOC dot-leaders or bare
     * numbers - otherwise falls back to a plain, honest placeholder rather
     * than fabricating a heading from noise.
     *
     * strcspn()'s returned position is a byte offset, but cutting
     * $afterMatch (already a valid, complete mb_substr() result) right
     * before a literal "\n"/"\r" byte is always safe: those bytes are pure
     * ASCII and can never be a UTF-8 continuation byte, so they can never
     * sit in the middle of another character - substr() there cannot split
     * anything, unlike the old fixed-radius slicing this replaced.
     */
    private static function deriveTitle(string $validUtf8Text, int $charOffset, int $matchCharLength, string $normalizedCode): string
    {
        $fallback = 'Error Code '.$normalizedCode;
        $afterMatch = mb_substr($validUtf8Text, $charOffset + $matchCharLength, 120, 'UTF-8');
        $newlinePos = strcspn($afterMatch, "\n\r");
        $candidate = trim(substr($afterMatch, 0, $newlinePos), " \t.-:");

        if ($candidate === '') {
            return $fallback;
        }
        if (! preg_match('/[A-Za-z]{3,}/', $candidate)) {
            return $fallback; // no real word-like content - likely dots/numbers/whitespace only
        }
        $dotCount = substr_count($candidate, '.');
        if ($dotCount > 0 && $dotCount >= (mb_strlen($candidate, 'UTF-8') * 0.3)) {
            return $fallback; // dominated by TOC dot-leaders
        }
        if (mb_strlen($candidate, 'UTF-8') > 100) {
            $candidate = rtrim(mb_substr($candidate, 0, 100, 'UTF-8'));
        }

        return $candidate;
    }
}
