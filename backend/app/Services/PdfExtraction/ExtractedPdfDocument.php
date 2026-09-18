<?php

namespace App\Services\PdfExtraction;

use Closure;
use Generator;

/**
 * Engine-agnostic result of a successful parse: a known page count plus lazy,
 * page-by-page text access. The resolver closure is called once per page as
 * pages() is iterated - never all at once - so DocumentExtractionService's
 * persist-then-release loop keeps the same one-page-in-memory-at-a-time behavior
 * it had before this abstraction existed, regardless of which extractor produced it.
 */
final class ExtractedPdfDocument
{
    public function __construct(private readonly int $totalPages, private readonly Closure $pageTextResolver) {}

    public function totalPages(): int
    {
        return $this->totalPages;
    }

    /** @return Generator<int, string> 1-indexed page number => extracted text */
    public function pages(): Generator
    {
        for ($pageNumber = 1; $pageNumber <= $this->totalPages; $pageNumber++) {
            yield $pageNumber => ($this->pageTextResolver)($pageNumber);
        }
    }
}
