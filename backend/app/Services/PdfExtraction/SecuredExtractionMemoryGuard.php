<?php

namespace App\Services\PdfExtraction;

/**
 * V1.5.5.1 - replaces the V1.5.5 guard that combined a 10x file-size
 * multiplier with a "never use more than 50% of memory_limit" fraction.
 * Those two conservative choices multiplied together (10x / 0.5 = effectively
 * requiring memory_limit >= 20x file size) rather than adding, and the result
 * was never checked against a real-world memory_limit until Production's was
 * actually read: 2048M, well above the measured 1074.1MB true peak for the
 * real 121,833,361-byte document, but below the ~2.3GB the old formula
 * silently required - a false RESOURCE_LIMIT rejection of a document that
 * fits comfortably in the memory actually available. See
 * docs/maintenance/V1.5.5_AESV2_EXTRACTION.md section 14 for the full
 * before/after calibration record.
 *
 * A pure, stateless calculation with every input as an explicit parameter -
 * no ini_get()/memory_get_usage() call lives here, only in
 * SecuredPdfTextExtractor's thin wrapper around it - specifically so this
 * class is testable with real, chosen numbers instead of needing tests to
 * actually allocate memory or manipulate php.ini to exercise it.
 */
final class SecuredExtractionMemoryGuard
{
    // Evidence: measured 8.82x peak-memory/file-size ratio for the real
    // production document (1074.1MB peak / 121,833,361 bytes - full
    // decrypt-and-build, not just Smalot's cheaper "detect encryption" path,
    // which is what DocumentExtractionService's separate, unchanged, general
    // 4x guard protects). 10x is a modest, documented margin above that
    // single measurement - not the measurement itself, and not stacked with
    // any additional fraction-of-limit reduction the way the old guard was.
    public const PARSER_MULTIPLIER = 10;

    // Evidence: a fresh Laravel console bootstrap that resolves the full
    // DocumentExtractionService/PdfTextExtractor dependency chain (the same
    // resolution a real queue job performs before calling process()) measures
    // ~20MB resident (memory_get_usage(true)), independently confirmed on
    // both PHP 8.2 and 8.4 - Laravel's own bootstrap footprint is not
    // meaningfully PHP-patch-version-dependent. 64MB is a ~3x margin above
    // that measured baseline, reserved for whatever a bare bootstrap
    // measurement does not capture: a real MySQL connection (Production; this
    // project's test suite uses sqlite), `queue:work`'s own loop/event-dispatch
    // overhead, GovernanceAudit's snapshot machinery, and general allocator
    // fragmentation - not a second multiplier stacked on top of
    // PARSER_MULTIPLIER, a single fixed amount subtracted once from whatever
    // memory is actually available.
    //
    // Deliberately NOT larger: an earlier draft of this constant (256MB, a
    // ~12x margin) was rejected during calibration because the reserve ALONE
    // then exceeded 128M - PHP's own stock CLI default, and the ambient
    // memory_limit this project's existing local/CI test runs use unless a
    // test overrides it. A reserve that size made the guard reject even a
    // trivially small file under any environment below ~276MB, regardless of
    // file size, which is not a defensible "safety margin" - it is a minimum
    // memory_limit floor smuggled into what is supposed to be a per-file
    // calculation. 64MB keeps a real, evidenced margin over the measured
    // baseline while leaving tiny files able to pass under modest
    // environments, and still leaves Production's real 2048M case with the
    // same comfortable margin as before (see REAL_KONICA calculation in
    // docs/maintenance/V1.5.5_AESV2_EXTRACTION.md section 14).
    public const FIXED_SAFETY_RESERVE_BYTES = 64 * 1024 * 1024;

    /**
     * @param  int  $fileSizeBytes  the stored PDF's size
     * @param  int|null  $memoryLimitBytes  the worker's configured memory_limit in bytes, or null for unlimited (php.ini `-1`)
     * @param  int  $currentUsageBytes  memory already allocated by this PHP process (memory_get_usage(true)) at the moment extraction is about to start
     */
    public static function fits(int $fileSizeBytes, ?int $memoryLimitBytes, int $currentUsageBytes): bool
    {
        if ($memoryLimitBytes === null) {
            return true; // unlimited - nothing to guard against, never treated as zero
        }

        $availableHeapBytes = $memoryLimitBytes - $currentUsageBytes - self::FIXED_SAFETY_RESERVE_BYTES;
        $requiredHeapBytes = $fileSizeBytes * self::PARSER_MULTIPLIER;

        return $availableHeapBytes >= $requiredHeapBytes;
    }

    public static function requiredHeapBytes(int $fileSizeBytes): int
    {
        return $fileSizeBytes * self::PARSER_MULTIPLIER;
    }
}
