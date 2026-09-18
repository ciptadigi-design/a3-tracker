<?php

namespace Tests\Unit;

use App\Services\PdfExtraction\SecuredExtractionMemoryGuard;
use PHPUnit\Framework\TestCase;

/**
 * V1.5.5.1 - pins the evidence-based replacement for V1.5.5's original guard
 * (10x file size compared against 50% of memory_limit), which multiplied its
 * two conservative choices together instead of adding them and ended up
 * silently requiring memory_limit >= ~20x file size - undetected until
 * Production's real memory_limit (2048M, read-only verified) was checked
 * against the real 121,833,361-byte document's measured 1074.1MB peak and
 * found to fall (2048M limit vs the ~2.3GB the old formula actually required)
 * on the wrong side of a guard that should have passed it.
 *
 * SecuredExtractionMemoryGuard::fits() is a pure function - every input is an
 * explicit parameter, so these tests exercise real, chosen memory_limit/
 * current-usage combinations without touching php.ini or allocating memory.
 */
class SecuredExtractionMemoryGuardTest extends TestCase
{
    /** The real production document this guard was calibrated against - see docs/maintenance/V1.5.5_AESV2_EXTRACTION.md. */
    private const REAL_KONICA_FILE_SIZE_BYTES = 121_833_361;

    /** A representative "just resolved the extraction dependency chain" baseline, matching the ~20MB measured locally (see SecuredExtractionMemoryGuard's own docblock). */
    private const REPRESENTATIVE_CURRENT_USAGE_BYTES = 20 * 1024 * 1024;

    private function mb(int $megabytes): int
    {
        return $megabytes * 1024 * 1024;
    }

    // --- Section E limit matrix, for the real Konica file size ---

    public function test_512m_rejects(): void
    {
        $this->assertFalse(SecuredExtractionMemoryGuard::fits(
            self::REAL_KONICA_FILE_SIZE_BYTES,
            $this->mb(512),
            self::REPRESENTATIVE_CURRENT_USAGE_BYTES,
        ));
    }

    public function test_1024m_rejects_because_measured_true_peak_alone_already_exceeds_it(): void
    {
        $this->assertFalse(SecuredExtractionMemoryGuard::fits(
            self::REAL_KONICA_FILE_SIZE_BYTES,
            $this->mb(1024),
            self::REPRESENTATIVE_CURRENT_USAGE_BYTES,
        ));
    }

    public function test_1500m_passes_with_a_real_but_modest_margin(): void
    {
        $this->assertTrue(SecuredExtractionMemoryGuard::fits(
            self::REAL_KONICA_FILE_SIZE_BYTES,
            $this->mb(1500),
            self::REPRESENTATIVE_CURRENT_USAGE_BYTES,
        ));
    }

    /**
     * The specific real-world case this milestone exists for: Production's
     * actual, read-only-verified memory_limit must pass for the real
     * document, unlike the old guard (which required ~2.3GB).
     */
    public function test_2048m_production_memory_limit_passes_for_the_real_konica_document(): void
    {
        $this->assertTrue(SecuredExtractionMemoryGuard::fits(
            self::REAL_KONICA_FILE_SIZE_BYTES,
            $this->mb(2048),
            self::REPRESENTATIVE_CURRENT_USAGE_BYTES,
        ));
    }

    public function test_3072m_passes_comfortably(): void
    {
        $this->assertTrue(SecuredExtractionMemoryGuard::fits(
            self::REAL_KONICA_FILE_SIZE_BYTES,
            $this->mb(3072),
            self::REPRESENTATIVE_CURRENT_USAGE_BYTES,
        ));
    }

    public function test_unlimited_memory_limit_always_passes_and_is_never_treated_as_zero(): void
    {
        $this->assertTrue(SecuredExtractionMemoryGuard::fits(
            self::REAL_KONICA_FILE_SIZE_BYTES,
            null, // the caller's phpMemoryLimitBytes() convention for -1/unlimited
            self::REPRESENTATIVE_CURRENT_USAGE_BYTES,
        ));
    }

    // --- Section F: guard must account for memory already consumed by the worker ---

    public function test_a_worker_with_significant_memory_already_allocated_is_not_treated_like_a_fresh_worker(): void
    {
        // Otherwise-passing 2048M limit, but this process has already used
        // 1.5GB for something else (e.g. a prior job in the same worker
        // lifecycle) - only ~548MB genuinely remains once the fixed reserve
        // is also subtracted, nowhere near the ~1.16GB this file needs.
        $this->assertFalse(SecuredExtractionMemoryGuard::fits(
            self::REAL_KONICA_FILE_SIZE_BYTES,
            $this->mb(2048),
            $this->mb(1500),
        ));
    }

    public function test_a_genuinely_fresh_worker_with_negligible_usage_passes_the_same_limit(): void
    {
        $this->assertTrue(SecuredExtractionMemoryGuard::fits(
            self::REAL_KONICA_FILE_SIZE_BYTES,
            $this->mb(2048),
            $this->mb(5), // a bare-minimum PHP CLI process footprint
        ));
    }

    // --- Formula shape: additive reserve, not a second multiplicative fraction ---

    public function test_the_boundary_is_exactly_where_available_heap_equals_required_heap(): void
    {
        $fileSize = 10 * 1024 * 1024; // 10MB, for round-number arithmetic
        $required = SecuredExtractionMemoryGuard::requiredHeapBytes($fileSize);
        $this->assertSame($fileSize * SecuredExtractionMemoryGuard::PARSER_MULTIPLIER, $required);

        $currentUsage = 0;
        $exactLimit = $required + $currentUsage + SecuredExtractionMemoryGuard::FIXED_SAFETY_RESERVE_BYTES;

        $this->assertTrue(SecuredExtractionMemoryGuard::fits($fileSize, $exactLimit, $currentUsage), 'exact boundary must pass (>=)');
        $this->assertFalse(SecuredExtractionMemoryGuard::fits($fileSize, $exactLimit - 1, $currentUsage), 'one byte under the boundary must reject');
    }

    // --- Guard must fail closed for a limit smaller than current usage + reserve alone ---

    public function test_current_usage_plus_reserve_alone_exceeding_the_limit_rejects_even_a_tiny_file(): void
    {
        $this->assertFalse(SecuredExtractionMemoryGuard::fits(
            1024, // 1KB file
            $this->mb(50), // smaller than current usage + the 64MB fixed reserve alone
            $this->mb(10),
        ));
    }

    // --- The reserve must stay small enough that tiny files still pass under
    // modest, realistic environments (e.g. PHP's own 128M CLI default) - this
    // is exactly the constraint that ruled out a larger reserve during
    // calibration (see SecuredExtractionMemoryGuard's own docblock).

    public function test_a_tiny_file_passes_even_under_phps_128m_stock_cli_default(): void
    {
        $this->assertTrue(SecuredExtractionMemoryGuard::fits(
            1156, // matches this project's real fixture PDFs
            $this->mb(128),
            self::REPRESENTATIVE_CURRENT_USAGE_BYTES,
        ));
    }
}
