<?php

namespace App\Services;

use App\Models\MaintenanceDocumentExtraction;
use App\Models\MaintenanceDocumentPage;
use App\Services\PdfExtraction\PdfExtractionException;
use App\Services\PdfExtraction\PdfTextExtractor;
use Illuminate\Support\Facades\Storage;

/**
 * PDF Knowledge Extraction Foundation (V1.5, refactored in V1.5.3). Pure extraction
 * mechanics only - no AI/knowledge processing here (that stays a future phase, same
 * as V1.3's manual knowledge-entry workflow was a deliberate foundation before any
 * OCR/AI step).
 *
 * This service owns the extraction *lifecycle* (stored file -> extractor -> page
 * persistence) and is no longer coupled to Smalot\PdfParser directly - it depends
 * on the PdfTextExtractor abstraction (bound to SmalotPdfTextExtractor in
 * AppServiceProvider) so a future alternative engine can be swapped in without
 * touching this class or ExtractMaintenanceDocumentJob.
 *
 * Page-by-page processing, never one big in-memory blob: each page's extracted
 * text is persisted and released immediately, one row at a time, instead of
 * accumulating into a single response payload or a combined string - so a
 * 500-page document never holds 500 pages of text in memory simultaneously, only
 * the current one (ExtractedPdfDocument::pages() resolves each page's text lazily
 * for the same reason).
 */
class DocumentExtractionService
{
    // Release accumulated PHP internal cycles periodically during long page loops
    // rather than only at the very end - keeps peak memory flatter across a large
    // page count instead of growing until the whole document is processed.
    private const GC_INTERVAL_PAGES = 25;

    // How many times a file's raw byte size the parse step is assumed to need at
    // peak (tokenized object tree + per-page text buffers) - a deliberately
    // conservative, documented estimate, not a measured guarantee.
    private const MEMORY_ESTIMATE_MULTIPLIER = 4;

    // Refuse to even attempt a parse expected to need more than this fraction of
    // the worker's configured memory_limit. The alternative is an uncatchable PHP
    // fatal ("Allowed memory size exhausted") that kills the worker process
    // mid-job with no catch/finally ever running - which is exactly the scenario
    // that would otherwise leave an extraction stuck PROCESSING forever.
    private const MEMORY_SAFETY_FRACTION = 0.5;

    public function __construct(private readonly PdfTextExtractor $extractor) {}

    public function process(MaintenanceDocumentExtraction $extraction): void
    {
        $document = $extraction->document;
        $storage = app(DocumentStorageService::class);
        if (! $storage->hasStoredFile($document)) {
            throw PdfExtractionException::fileMissing();
        }
        $disk = Storage::disk($document->storage_disk);
        if (! $disk->exists($document->file_path)) {
            throw PdfExtractionException::fileMissing();
        }

        $this->assertWithinResourceLimits((int) ($document->file_size ?? $disk->size($document->file_path)));

        $extracted = $this->extractor->extract($disk->path($document->file_path));
        $totalPages = $extracted->totalPages();
        $extraction->update(['total_pages' => $totalPages, 'processed_pages' => 0]);

        foreach ($extracted->pages() as $pageNumber => $text) {
            MaintenanceDocumentPage::updateOrCreate(
                ['document_id' => $document->id, 'page_number' => $pageNumber],
                ['raw_text' => $text, 'metadata' => ['char_count' => mb_strlen($text)]]
            );
            unset($text);
            $extraction->increment('processed_pages');
            if ($pageNumber % self::GC_INTERVAL_PAGES === 0) {
                gc_collect_cycles();
            }
        }
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
