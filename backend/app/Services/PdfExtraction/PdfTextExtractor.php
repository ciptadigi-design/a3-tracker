<?php

namespace App\Services\PdfExtraction;

/**
 * V1.5.3 - extraction-engine abstraction. DocumentExtractionService orchestrates the
 * extraction lifecycle (stored file -> extractor -> page persistence) without
 * knowing which underlying PDF library performed the parse. Smalot\PdfParser is the
 * only implementation today (SmalotPdfTextExtractor); an alternative engine (e.g.
 * something that can handle secured/encrypted PDFs) can implement this same
 * interface later without touching the service or the job.
 */
interface PdfTextExtractor
{
    /**
     * @throws PdfExtractionException on any classified parse failure (secured PDF,
     *                                corrupt/invalid structure, or anything else the implementation cannot recover from)
     */
    public function extract(string $absolutePath): ExtractedPdfDocument;
}
