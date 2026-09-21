<?php

namespace App\Services\KnowledgeReview;

/**
 * V1.8 - decides whether a title is merely the detector's generic placeholder, which must never be
 * published as knowledge.
 *
 * PdfKnowledgeCandidateDetector::deriveTitle() falls back to "Error Code C-XXXX" whenever the text after
 * a match does not look like a real heading (about 87% of HIGH candidates on the real import). The
 * check is convention-based, not hard-coded to any one code: every error-code token (C-3102, C3102,
 * "C - 3102") is stripped first, and the title is a placeholder only if what is left carries no
 * information ("Error Code", "Code", "Untitled", nothing at all). A human title that merely CONTAINS the
 * code ("C-3102 Fuser temperature abnormal", "Fuser error (C-3102)") is not a placeholder.
 */
final class PlaceholderTitle
{
    private const EMPTY_WORDS = ['', 'errorcode', 'errorcodes', 'error', 'code', 'codes', 'untitled', 'title', 'todo', 'tbd', 'na'];

    public static function isPlaceholder(?string $title, string $normalizedCode): bool
    {
        $t = mb_strtolower(trim((string) $title));
        if ($t === '') {
            return true;
        }
        // Strip every error-code shaped token (not only this group's), then everything that is not a letter or digit.
        $t = preg_replace('/\bc[\s-]*\d{4}\b/u', ' ', $t) ?? $t;
        $t = preg_replace('/[^a-z0-9]+/u', '', $t) ?? $t;

        return in_array($t, self::EMPTY_WORDS, true);
    }
}
