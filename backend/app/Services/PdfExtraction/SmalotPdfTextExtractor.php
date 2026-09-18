<?php

namespace App\Services\PdfExtraction;

use Smalot\PdfParser\Parser;
use Throwable;

/**
 * The only PdfTextExtractor implementation today. smalot/pdfparser is a pure-PHP
 * parser (no shell_exec/exec/proc_open, no OS binary) - required for Hostinger
 * shared hosting, which has shell_exec disabled and no persistent worker/Supervisor
 * (see docs/hostinger/HOSTINGER_QUEUE_SETUP.md). It has no decryption engine at all
 * (confirmed by inspecting vendor/smalot/pdfparser - no RC4/AES cipher
 * implementation exists in the library), so a secured/encrypted PDF is always a
 * permanent UNSUPPORTED_PDF_SECURITY failure for this extractor, never a transient
 * one - see docs/maintenance/V1.5.3_SECURED_PDF_INVESTIGATION.md for the fallback
 * options considered and why none were adopted this release.
 */
class SmalotPdfTextExtractor implements PdfTextExtractor
{
    // The exact, stable message smalot/pdfparser throws for an /Encrypt trailer
    // entry (vendor/smalot/pdfparser/src/Smalot/PdfParser/Parser.php) - this is the
    // one Smalot failure mode this extractor classifies with certainty; every other
    // parse failure (including its own "Possible secured file" guess when the object
    // list is simply empty/malformed) is treated as a corrupt/invalid structure
    // instead, since only this message reflects Smalot actually having found a real
    // /Encrypt entry in the trailer.
    private const SECURED_PDF_MESSAGE = 'Secured pdf file are currently not supported.';

    public function extract(string $absolutePath): ExtractedPdfDocument
    {
        try {
            $document = (new Parser)->parseFile($absolutePath);
        } catch (Throwable $e) {
            throw $e->getMessage() === self::SECURED_PDF_MESSAGE
                ? PdfExtractionException::unsupportedSecurity($e)
                : PdfExtractionException::invalidOrCorrupt($e);
        }

        $pages = $document->getPages();
        $totalPages = count($pages);

        return new ExtractedPdfDocument($totalPages, static fn (int $pageNumber): string => $pages[$pageNumber - 1]->getText());
    }
}
