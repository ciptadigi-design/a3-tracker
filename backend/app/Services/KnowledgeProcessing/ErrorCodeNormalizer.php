<?php

namespace App\Services\KnowledgeProcessing;

/**
 * V1.6 - deterministic error-code detection and normalization. No LLM/AI:
 * everything here is a fixed regex plus documented, evidence-based decisions.
 *
 * Detection format was NOT assumed - it was determined by sampling the real
 * Production Konica extraction (121.8MB, 2639 pages) read-only before writing
 * this class. That sample proved two things conclusively:
 *
 *  - `C-DDDD` (a literal dash, exactly 4 digits, e.g. "C-2451") is the real
 *    troubleshooting-code format: each distinct token appears on only a
 *    handful of pages (1-5), exactly the signature of a real code appearing
 *    at its own definition plus a table-of-contents/cross-reference line.
 *  - `CDDDD` (NO dash, e.g. "C1070") is NOT an equivalent representation of
 *    the same thing - it is the machine model number, appearing as a running
 *    page header/footer on 2454 of the document's 2639 pages (93%). Treating
 *    it as a code candidate (as a naive reading of "C-2801 / C2801 / C 2801
 *    are potentially equivalent formatting" would suggest) would have made
 *    nearly every page a false-positive candidate.
 *
 * detectCandidates() therefore only ever matches the dash form (optional
 * surrounding whitespace, to tolerate "C - 2451" / "C- 2451" spacing
 * variants actually seen in extracted text) - never bare `C\d{4}`.
 *
 * normalize() is separate and more lenient, because it also has to compare
 * against `machine_error_codes.code` values that predate this milestone and
 * may have been typed by hand without a dash (V1.3 manual entry never
 * enforced a format) - collision detection (Section J) needs both sides
 * compared on equal terms, even though detection from raw PDF text does not
 * trust the dash-less form as a genuine candidate signal.
 */
final class ErrorCodeNormalizer
{
    /** Strict, dash-required - see class docblock for why the dash is load-bearing here. */
    private const DETECT_PATTERN = '/\bC\s*-\s*(\d{4})\b/i';

    /** Lenient - for comparing an already-known code value (from either side) against a normalized candidate. */
    private const LOOSE_PATTERN = '/^C[\s-]*(\d{4})$/i';

    /**
     * @return list<array{code: string, normalized_code: string, offset: int}>
     *                                                                         offset is the byte offset of the match start within $text, for
     *                                                                         bounded-context extraction by the caller.
     */
    public static function detectCandidates(string $text): array
    {
        if (! preg_match_all(self::DETECT_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $candidates = [];
        foreach ($matches[0] as $i => [$rawMatch, $offset]) {
            $digits = $matches[1][$i][0];
            $candidates[] = [
                'code' => $rawMatch,
                'normalized_code' => 'C-'.$digits,
                'offset' => $offset,
            ];
        }

        return $candidates;
    }

    /**
     * Normalize an already-known code string (e.g. from machine_error_codes,
     * or a manually-typed knowledge entry) for collision comparison. Returns
     * null if it does not match the C+4-digit shape at all (never guesses).
     */
    public static function normalize(string $rawCode): ?string
    {
        $trimmed = trim($rawCode);
        if ($trimmed === '' || ! preg_match(self::LOOSE_PATTERN, $trimmed, $m)) {
            return null;
        }

        return 'C-'.$m[1];
    }
}
