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
    // separately-calibrated guard rather than trusting the caller's.
    //
    // Measured directly against the real 121,833,361-byte production document
    // (see docs/maintenance/V1.5.5_AESV2_EXTRACTION.md section 7): peak 1074.1MB,
    // an 8.82x ratio - well above the 4x general-purpose estimate. 10x is a
    // deliberately padded safety margin above that measurement, not the
    // measurement itself, matching DocumentExtractionService's own "deliberately
    // conservative, documented estimate, not a measured guarantee" precedent.
    private const MEMORY_ESTIMATE_MULTIPLIER = 10;

    private const MEMORY_SAFETY_FRACTION = 0.5;

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
        if ($memoryLimitBytes === null) {
            return; // unlimited (-1) or unreadable ini value - nothing to guard against
        }
        $estimatedPeakBytes = $fileSizeBytes * self::MEMORY_ESTIMATE_MULTIPLIER;
        if ($estimatedPeakBytes > $memoryLimitBytes * self::MEMORY_SAFETY_FRACTION) {
            throw PdfExtractionException::resourceLimit($fileSizeBytes, $memoryLimitBytes);
        }
    }

    private function phpMemoryLimitBytes(): ?int
    {
        $value = trim((string) ini_get('memory_limit'));
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
