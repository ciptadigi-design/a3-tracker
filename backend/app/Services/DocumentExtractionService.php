<?php

namespace App\Services;

use App\Models\MaintenanceDocumentExtraction;
use App\Models\MaintenanceDocumentPage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Smalot\PdfParser\Parser;

/**
 * PDF Knowledge Extraction Foundation (V1.5). Pure extraction mechanics only - no
 * AI/knowledge processing here (that stays a future phase, same as V1.3's manual
 * knowledge-entry workflow was a deliberate foundation before any OCR/AI step).
 *
 * Page-by-page processing, never one big in-memory blob: Smalot\PdfParser\Parser
 * necessarily loads and tokenizes the PDF's raw byte structure in one pass (no
 * pure-PHP PDF library can truly stream that part - there is no external process
 * or binary dependency introduced instead, since Hostinger's shared/jailed shell
 * cannot be relied on for a `pdftotext`-style binary). What this service controls
 * is everything downstream of that parse: each page's extracted text is persisted
 * and released immediately, one row at a time, instead of accumulating into a
 * single response payload or a combined string - so a 500-page document never
 * holds 500 pages of text in memory simultaneously, only the current one.
 *
 * For files at the top of the supported 100-250MB range, the parse step itself is
 * the real memory ceiling, bounded by the queue worker process's own PHP
 * memory_limit (a CLI worker process, not the web request's php-fpm limit) -
 * documented as a known constraint, not solved here.
 */
class DocumentExtractionService
{
    // Release accumulated PHP internal cycles periodically during long page loops
    // rather than only at the very end - keeps peak memory flatter across a large
    // page count instead of growing until the whole document is processed.
    private const GC_INTERVAL_PAGES = 25;

    public function process(MaintenanceDocumentExtraction $extraction): void
    {
        $document = $extraction->document;
        $storage = app(DocumentStorageService::class);
        if (! $storage->hasStoredFile($document)) {
            throw new RuntimeException('Document has no stored PDF file to extract.');
        }
        $disk = Storage::disk($document->storage_disk);
        if (! $disk->exists($document->file_path)) {
            throw new RuntimeException('Stored PDF file is missing from disk.');
        }

        $parser = new Parser;
        $pdf = $parser->parseFile($disk->path($document->file_path));
        $pages = $pdf->getPages();
        $totalPages = count($pages);
        $extraction->update(['total_pages' => $totalPages, 'processed_pages' => 0]);

        foreach ($pages as $index => $page) {
            $pageNumber = $index + 1;
            $text = $page->getText();
            MaintenanceDocumentPage::updateOrCreate(
                ['document_id' => $document->id, 'page_number' => $pageNumber],
                ['raw_text' => $text, 'metadata' => ['char_count' => mb_strlen($text)]]
            );
            unset($text, $page);
            $extraction->increment('processed_pages');
            if ($pageNumber % self::GC_INTERVAL_PAGES === 0) {
                gc_collect_cycles();
            }
        }
        unset($pages, $pdf);
    }
}
