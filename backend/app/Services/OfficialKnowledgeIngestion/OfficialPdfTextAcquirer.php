<?php

namespace App\Services\OfficialKnowledgeIngestion;

interface OfficialPdfTextAcquirer
{
    /**
     * @param  list<int>  $pageNumbers
     * @return array<int, string>
     */
    public function acquire(string $pdfPath, array $pageNumbers): array;
}
