<?php

namespace App\Services\PdfExtraction;

/**
 * V1.5.3 - permanent-vs-transient extraction failure classification. The backend
 * owns this classification (see PdfExtractionException) - the frontend renders
 * from error_code, never by pattern-matching a raw exception message.
 */
enum PdfExtractionErrorCode: string
{
    case UNSUPPORTED_PDF_SECURITY = 'UNSUPPORTED_PDF_SECURITY';
    case INVALID_OR_CORRUPT_PDF = 'INVALID_OR_CORRUPT_PDF';
    case FILE_MISSING = 'FILE_MISSING';
    case EXTRACTION_RUNTIME_FAILURE = 'EXTRACTION_RUNTIME_FAILURE';
    case RESOURCE_LIMIT = 'RESOURCE_LIMIT';

    /**
     * Whether retrying the *same* extraction request can plausibly succeed.
     *
     * UNSUPPORTED_PDF_SECURITY/INVALID_OR_CORRUPT_PDF/FILE_MISSING are properties of
     * the stored file itself - nothing about a queue retry changes them.
     * RESOURCE_LIMIT is judged permanent too: it is only ever raised against the
     * worker's static memory_limit (see DocumentExtractionService), which a retry on
     * the same worker cannot change either. Only EXTRACTION_RUNTIME_FAILURE (an
     * unclassified/unexpected Throwable - e.g. a transient DB error) keeps Laravel's
     * normal tries/backoff behavior.
     */
    public function isPermanent(): bool
    {
        return $this !== self::EXTRACTION_RUNTIME_FAILURE;
    }

    /** Safe for direct display to any user - no paths, stack traces, or SQL. */
    public function userMessage(): string
    {
        return match ($this) {
            self::UNSUPPORTED_PDF_SECURITY => 'This PDF uses a security/encryption format that the current extraction engine cannot process. The original PDF remains safe and available.',
            self::INVALID_OR_CORRUPT_PDF => 'This PDF file could not be read. It may be corrupted or not a valid PDF.',
            self::FILE_MISSING => 'The stored PDF file could not be found. Try re-uploading the document.',
            self::RESOURCE_LIMIT => 'This document is too large for the extraction worker to process safely right now.',
            self::EXTRACTION_RUNTIME_FAILURE => 'Extraction failed due to an unexpected error. It will be retried automatically.',
        };
    }
}
