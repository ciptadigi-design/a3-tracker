<?php

namespace App\Services\PdfExtraction;

use RuntimeException;
use Throwable;

/**
 * The one exception type extraction code throws - always carrying a machine-readable
 * PdfExtractionErrorCode. getMessage() is diagnostic (safe to log) but is
 * deliberately never anything that could contain a filesystem path or a third-party
 * library's raw internal text; PdfExtractionErrorCode::userMessage() is what the API
 * response and frontend actually show.
 */
class PdfExtractionException extends RuntimeException
{
    public function __construct(public readonly PdfExtractionErrorCode $errorCode, string $diagnosticMessage, ?Throwable $previous = null)
    {
        parent::__construct($diagnosticMessage, 0, $previous);
    }

    public static function unsupportedSecurity(?Throwable $previous = null): self
    {
        return new self(PdfExtractionErrorCode::UNSUPPORTED_PDF_SECURITY, 'Parser reported an unsupported PDF security/encryption structure.', $previous);
    }

    public static function invalidOrCorrupt(?Throwable $previous = null): self
    {
        return new self(PdfExtractionErrorCode::INVALID_OR_CORRUPT_PDF, 'Parser could not read the PDF structure (invalid or corrupt file).', $previous);
    }

    public static function fileMissing(): self
    {
        return new self(PdfExtractionErrorCode::FILE_MISSING, 'Stored PDF file is missing from disk.');
    }

    public static function resourceLimit(int $fileSizeBytes, int $memoryLimitBytes): self
    {
        return new self(PdfExtractionErrorCode::RESOURCE_LIMIT, "Estimated parse memory for a {$fileSizeBytes}-byte file exceeds the worker's {$memoryLimitBytes}-byte memory_limit safety threshold.");
    }

    public static function runtimeFailure(Throwable $previous): self
    {
        return new self(PdfExtractionErrorCode::EXTRACTION_RUNTIME_FAILURE, 'Unclassified extraction failure: '.$previous::class, $previous);
    }
}
