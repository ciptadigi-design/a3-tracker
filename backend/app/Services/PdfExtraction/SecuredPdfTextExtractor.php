<?php

namespace App\Services\PdfExtraction;

use Smalot\PdfParser\RawData\RawDataParser;
use Throwable;

/**
 * V1.5.5 - the AESV2-supported-profile extraction engine. Only ever reached
 * by ProfileAwarePdfTextExtractor after PdfSecurityInspector has already
 * confirmed the exact supported profile (Standard Security Handler, /V 4,
 * /R 4, AES-128, empty user password) - this class re-authenticates
 * independently anyway (fail-closed even if ever called directly) rather
 * than trusting a caller's prior check.
 *
 * No temp file, no PDF rewrite/serialization: decrypts every string/stream
 * in RawDataParser's already-parsed object map (SecuredObjectDecryptor),
 * then builds a Smalot\PdfParser\Document from that decrypted map via
 * SecuredParserBridge - the exact same object-construction code Smalot uses
 * for an unencrypted file, reused unmodified. See
 * docs/maintenance/V1.5.5_AESV2_EXTRACTION.md for the full architecture and
 * why this was chosen over a decrypt-to-temp-file rewrite.
 */
final class SecuredPdfTextExtractor implements PdfTextExtractor
{
    // DocumentExtractionService's own pre-flight RESOURCE_LIMIT guard (4x file
    // size) is calibrated for Smalot's plain parse path - reaching its /Encrypt
    // rejection point, or fully parsing an unencrypted file. This class does
    // substantially more work per byte (decrypt every string/stream, THEN build
    // Smalot's full typed object graph for potentially tens of thousands of
    // objects - the real production document has 42,236), so it needs its own,
    // separately-calibrated guard rather than trusting the caller's - see
    // SecuredExtractionMemoryGuard (V1.5.5.1) for the formula and its evidence.
    public function __construct(
        private readonly PdfObjectDictionaryReader $reader = new PdfObjectDictionaryReader,
    ) {}

    public function extract(string $absolutePath): ExtractedPdfDocument
    {
        if (! is_file($absolutePath)) {
            throw PdfExtractionException::fileMissing();
        }
        $this->assertWithinResourceLimits((int) filesize($absolutePath));

        $content = file_get_contents($absolutePath);
        if ($content === false) {
            throw PdfExtractionException::fileMissing();
        }

        try {
            [$xref, $data] = (new RawDataParser)->parseData($content);
        } catch (Throwable $e) {
            throw PdfExtractionException::invalidOrCorrupt($e);
        }

        $encryptRef = $xref['trailer']['encrypt'] ?? null;
        if (! is_string($encryptRef)) {
            // Not actually encrypted - ProfileAwarePdfTextExtractor only routes here
            // after Smalot itself detected /Encrypt, so this should not happen; fail
            // closed rather than silently falling through to an unencrypted parse.
            throw PdfExtractionException::unsupportedSecurity();
        }

        try {
            $encryptDict = $this->reader->resolveDictionary($encryptRef, $data);
        } catch (Throwable $e) {
            throw PdfExtractionException::invalidOrCorrupt($e);
        }

        $fileId = $this->reader->resolveFileId($xref['trailer']['id'][0] ?? null);
        if ($fileId === null) {
            throw PdfExtractionException::unsupportedSecurity();
        }

        $handler = new AesV2StandardSecurityHandler($encryptDict, $fileId);
        if (! $handler->authenticateEmptyUserPassword()) {
            throw PdfExtractionException::unsupportedSecurity();
        }

        try {
            $decrypted = (new SecuredObjectDecryptor($handler, $this->reader))->decryptAll($data, $encryptRef);
            $document = (new SecuredParserBridge)->buildDocument($xref, $decrypted);
        } catch (Throwable $e) {
            throw PdfExtractionException::invalidOrCorrupt($e);
        }

        $pages = $document->getPages();
        $totalPages = count($pages);

        return new ExtractedPdfDocument($totalPages, static fn (int $pageNumber): string => $pages[$pageNumber - 1]->getText());
    }

    private function assertWithinResourceLimits(int $fileSizeBytes): void
    {
        $memoryLimitBytes = $this->phpMemoryLimitBytes();
        $currentUsageBytes = memory_get_usage(true);
        if (! SecuredExtractionMemoryGuard::fits($fileSizeBytes, $memoryLimitBytes, $currentUsageBytes)) {
            throw PdfExtractionException::resourceLimit($fileSizeBytes, $memoryLimitBytes ?? -1);
        }
    }

    private function phpMemoryLimitBytes(): ?int
    {
        return self::parseMemoryLimit((string) ini_get('memory_limit'));
    }

    /**
     * Pure string-parsing half of phpMemoryLimitBytes(), pulled out so it is
     * directly testable with real, chosen ini strings (including malformed
     * ones PHP itself would refuse to actually apply via ini_set - see
     * AesV2SecuredExtractionTest) without touching php.ini.
     *
     * A value with no recognized numeric leading part (including anything
     * malformed) resolves to 0 bytes, which SecuredExtractionMemoryGuard
     * always rejects against - failing closed exactly like a memory_limit
     * that is genuinely too small, never silently treated as unlimited.
     */
    public static function parseMemoryLimit(string $rawValue): ?int
    {
        $value = trim($rawValue);
        if ($value === '' || $value === '-1') {
            return null;
        }
        $unit = strtoupper(substr($value, -1));
        $number = (float) $value;

        return (int) match ($unit) {
            'G' => $number * 1024 * 1024 * 1024,
            'M' => $number * 1024 * 1024,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
