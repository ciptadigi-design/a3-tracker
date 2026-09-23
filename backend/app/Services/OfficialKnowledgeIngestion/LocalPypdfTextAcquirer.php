<?php

namespace App\Services\OfficialKnowledgeIngestion;

use RuntimeException;
use Symfony\Component\Process\Process;

final class LocalPypdfTextAcquirer implements OfficialPdfTextAcquirer
{
    /** @param list<int> $pageNumbers */
    public function acquire(string $pdfPath, array $pageNumbers): array
    {
        $pages = array_values(array_unique(array_map('intval', $pageNumbers)));
        sort($pages);
        if ($pages === [] || collect($pages)->contains(fn (int $page): bool => $page < 1)) {
            throw new RuntimeException('At least one positive physical PDF page number is required.');
        }

        $script = <<<'PYTHON'
import json
import sys
from pypdf import PdfReader

path = sys.argv[1]
requested = json.loads(sys.argv[2])
reader = PdfReader(path)
result = {}
for page_number in requested:
    if page_number < 1 or page_number > len(reader.pages):
        raise RuntimeError(f"Requested PDF page {page_number} is outside the document")
    result[str(page_number)] = reader.pages[page_number - 1].extract_text(extraction_mode="layout") or ""
print(json.dumps(result))
PYTHON;

        $process = new Process(['python3', '-c', $script, $pdfPath, json_encode($pages, JSON_THROW_ON_ERROR)]);
        $process->setTimeout(120);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Local page-scoped PDF extraction failed: '.trim($process->getErrorOutput()));
        }

        $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $result = [];
        foreach ($pages as $page) {
            if (! array_key_exists((string) $page, $decoded) || ! is_string($decoded[(string) $page])) {
                throw new RuntimeException("Local PDF extraction did not return requested page {$page}.");
            }
            $result[$page] = $decoded[(string) $page];
        }

        return $result;
    }
}
