<?php

namespace App\Services\OfficialKnowledgeIngestion\Jam;

interface OfficialPdfSegmentAcquirer
{
    /**
     * Positioned text runs per physical page, in PDF user-space points (origin bottom-left).
     *
     * @param  list<int>  $pageNumbers
     * @return array<int, list<array{0: float, 1: float, 2: string}>> page => [[tx, ty, text], ...]
     */
    public function acquire(string $pdfPath, array $pageNumbers): array;
}
