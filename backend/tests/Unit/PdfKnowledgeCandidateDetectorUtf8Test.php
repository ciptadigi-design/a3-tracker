<?php

namespace Tests\Unit;

use App\Services\KnowledgeProcessing\PdfKnowledgeCandidateDetector;
use PHPUnit\Framework\TestCase;

/**
 * V1.6.1 - UTF-8-safe bounded context regression. The real Production run
 * (2639-page Konica document) failed with a MySQL "Incorrect string value:
 * '\xE2'" on `description` because the old implementation sliced context at
 * an arbitrary fixed BYTE radius (+/-220), which real manual text's
 * legitimate multi-byte Unicode punctuation can land in the middle of.
 *
 * Every fixture here is synthetic, harmless punctuation/Latin text -
 * never real Konica manual content.
 */
class PdfKnowledgeCandidateDetectorUtf8Test extends TestCase
{
    private const EM_DASH = "\u{2014}";   // U+2014, 3 bytes (E2 80 94)

    private const EN_DASH = "\u{2013}";   // U+2013, 3 bytes (E2 80 93)

    private const CURLY_QUOTE = "\u{2019}"; // U+2019, 3 bytes (E2 80 99)

    private const BULLET = "\u{2022}";    // U+2022, 3 bytes (E2 80 A2)

    private const DEGREE = "\u{00B0}";    // U+00B0, 2 bytes (C2 B0)

    private const E_ACUTE = "\u{00E9}";   // U+00E9, 2 bytes (C3 A9)

    /**
     * The exact mechanism the old implementation broke on: given N
     * repetitions of a 3-byte character immediately before a match, a
     * byte-radius cut of 220 bytes back from the match's byte offset lands
     * mid-character, because 220 is not a multiple of 3. This demonstrates
     * the regression directly (computed here, not by keeping the broken
     * code path in production) before asserting the real implementation
     * avoids it.
     */
    public function test_the_old_byte_radius_approach_would_have_produced_invalid_utf8_at_the_start_boundary(): void
    {
        $prefix = str_repeat(self::EM_DASH, 100); // 300 bytes, 100 chars, purely 3-byte sequences
        $text = $prefix.'C-1001 trailing filler text after the match continues here.';
        $matchByteOffset = strlen($prefix); // byte offset of "C-1001" - a multiple of 3

        $oldStyleStart = max(0, $matchByteOffset - 220);
        $oldStyleSlice = substr($text, $oldStyleStart, 220 + 6 + 220);

        $this->assertFalse(mb_check_encoding($oldStyleSlice, 'UTF-8'), 'sanity check: the old byte-radius arithmetic must actually reproduce the real failure mode for this fixture, or the regression test proves nothing');
    }

    public function test_bounded_context_is_always_valid_utf8_with_a_multibyte_run_at_the_start_boundary(): void
    {
        foreach ([self::EM_DASH, self::EN_DASH, self::CURLY_QUOTE, self::BULLET, self::DEGREE, self::E_ACUTE] as $char) {
            $prefix = str_repeat($char, 150);
            $pageText = $prefix.'C-1001 trailing filler text after the match continues here for context.';

            $candidates = PdfKnowledgeCandidateDetector::detectOnPage($pageText, null);

            $this->assertCount(1, $candidates, "detection must still succeed with a $char run before the match");
            $this->assertTrue(mb_check_encoding($candidates[0]['description'], 'UTF-8'), "description must be valid UTF-8 with a $char run at the start boundary");
            $this->assertSame('C-1001', $candidates[0]['code']);
        }
    }

    public function test_bounded_context_is_always_valid_utf8_with_a_multibyte_run_at_the_end_boundary(): void
    {
        foreach ([self::EM_DASH, self::EN_DASH, self::CURLY_QUOTE, self::BULLET, self::DEGREE, self::E_ACUTE] as $char) {
            $suffix = str_repeat($char, 150);
            $pageText = 'Leading filler text before the match continues here for context. C-1001'.$suffix;

            $candidates = PdfKnowledgeCandidateDetector::detectOnPage($pageText, null);

            $this->assertCount(1, $candidates, "detection must still succeed with a $char run after the match");
            $this->assertTrue(mb_check_encoding($candidates[0]['description'], 'UTF-8'), "description must be valid UTF-8 with a $char run at the end boundary");
        }
    }

    public function test_bounded_context_is_valid_utf8_with_multibyte_runs_on_both_sides(): void
    {
        $prefix = str_repeat(self::CURLY_QUOTE, 150);
        $suffix = str_repeat(self::EM_DASH, 150);
        $pageText = $prefix.'C-2222'.$suffix;

        $candidates = PdfKnowledgeCandidateDetector::detectOnPage($pageText, null);

        $this->assertCount(1, $candidates);
        $this->assertTrue(mb_check_encoding($candidates[0]['description'], 'UTF-8'));
        $this->assertSame('C-2222', $candidates[0]['normalized_code']);
    }

    // --- context remains bounded, never a whole page ---

    public function test_description_remains_bounded_even_with_a_very_long_multibyte_heavy_page(): void
    {
        $prefix = str_repeat(self::EM_DASH, 5000);
        $suffix = str_repeat(self::BULLET, 5000);
        $pageText = $prefix.'C-3333'.$suffix;

        $candidates = PdfKnowledgeCandidateDetector::detectOnPage($pageText, null);

        // Bounded to roughly 2x the 220-char radius plus the match itself -
        // nowhere near the ~15,000-character full page.
        $this->assertLessThan(600, mb_strlen($candidates[0]['description'], 'UTF-8'));
    }

    // --- no replacement-character corruption introduced merely to hide invalid slicing ---

    public function test_valid_input_never_gains_a_unicode_replacement_character(): void
    {
        $prefix = str_repeat(self::E_ACUTE, 150);
        $pageText = $prefix.'C-4444 café résumé naïve façade filler text continues here.';

        $candidates = PdfKnowledgeCandidateDetector::detectOnPage($pageText, null);

        $this->assertStringNotContainsString("\u{FFFD}", $candidates[0]['description'], 'valid UTF-8 input must never be corrupted with a replacement character just to force a boundary');
    }

    // --- already-corrupt input is handled separately and safely, never crashes ---

    public function test_already_invalid_utf8_input_is_sanitized_rather_than_crashing_or_corrupting_the_match(): void
    {
        // A single stray continuation byte (0x80) with no valid lead byte -
        // genuinely invalid UTF-8, distinct from a valid string sliced
        // incorrectly (see class docblock).
        $pageText = "Filler text \x80 before the match. C-5555 more text after.";

        $candidates = PdfKnowledgeCandidateDetector::detectOnPage($pageText, null);

        $this->assertCount(1, $candidates);
        $this->assertSame('C-5555', $candidates[0]['code']);
        $this->assertTrue(mb_check_encoding($candidates[0]['description'], 'UTF-8'));
    }

    // --- ASCII-only behavior is unchanged (regression) ---

    public function test_ascii_only_page_behavior_is_unchanged(): void
    {
        $pageText = "Troubleshooting\nC-6001\nCause:\nFixture cause only.\nAction:\nFixture action only.";

        $candidates = PdfKnowledgeCandidateDetector::detectOnPage($pageText, null);

        $this->assertCount(1, $candidates);
        $this->assertSame('C-6001', $candidates[0]['normalized_code']);
        $this->assertSame('HIGH', $candidates[0]['evidence']);
        $this->assertStringContainsString('Cause:', $candidates[0]['description']);
    }

    // --- deterministic across repeated runs ---

    public function test_output_is_deterministic_across_repeated_calls(): void
    {
        $prefix = str_repeat(self::EM_DASH, 150);
        $pageText = $prefix.'C-7001 trailing filler text after the match continues here.';

        $first = PdfKnowledgeCandidateDetector::detectOnPage($pageText, null);
        $second = PdfKnowledgeCandidateDetector::detectOnPage($pageText, null);

        $this->assertSame($first, $second);
    }

    // --- code itself remains intact regardless of surrounding multi-byte content ---

    public function test_the_matched_code_itself_is_never_altered_by_surrounding_multibyte_content(): void
    {
        $prefix = str_repeat(self::DEGREE, 219); // one short of the radius, still exercises the boundary
        $pageText = $prefix.'C-8080'.str_repeat(self::BULLET, 221);

        $candidates = PdfKnowledgeCandidateDetector::detectOnPage($pageText, null);

        $this->assertSame('C-8080', $candidates[0]['code']);
        $this->assertSame('C-8080', $candidates[0]['normalized_code']);
    }
}
