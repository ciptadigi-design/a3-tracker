<?php

namespace App\Services\OfficialKnowledgeIngestion\Jam;

use App\Services\OfficialKnowledgeIngestion\OfficialKnowledgePythonRuntime;
use RuntimeException;

/**
 * Positioned text runs from the pinned pypdf runtime. Table cells are laid out with true x/y
 * coordinates here, which the flat layout text cannot give (its column offsets drift per row).
 * Uses pypdf's layout-mode run extraction, so the pypdf version is asserted exactly.
 */
final class LocalPypdfSegmentAcquirer implements OfficialPdfSegmentAcquirer
{
    public function __construct(private readonly OfficialKnowledgePythonRuntime $runtime) {}

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
import pypdf
from pypdf import PdfReader
from pypdf.generic import ContentStream
from pypdf._text_extraction import _layout_mode as layout_mode

if pypdf.__version__ != "6.14.2":
    raise RuntimeError("positioned extraction requires pypdf 6.14.2 exactly")
path = sys.argv[1]
requested = json.loads(sys.argv[2])
reader = PdfReader(path)
if reader.is_encrypted:
    reader.decrypt("")
result = {}
for page_number in requested:
    if page_number < 1 or page_number > len(reader.pages):
        raise RuntimeError(f"Requested PDF page {page_number} is outside the document")
    page = reader.pages[page_number - 1]
    ops = iter(ContentStream(page["/Contents"].get_object(), reader, "bytes").operations)
    runs = layout_mode.text_show_operations(ops, page._layout_mode_fonts(), True)
    result[str(page_number)] = [[round(run["tx"], 1), round(run["ty"], 1), run["text"]] for run in runs]
print(json.dumps(result))
PYTHON;

        $decoded = json_decode($this->runtime->extract($script, $pdfPath, $pages), true, 512, JSON_THROW_ON_ERROR);
        $result = [];
        foreach ($pages as $page) {
            $runs = $decoded[(string) $page] ?? null;
            if (! is_array($runs)) {
                throw new RuntimeException("Positioned extraction did not return requested page {$page}.");
            }
            $result[$page] = array_map(static fn (array $run): array => [(float) $run[0], (float) $run[1], (string) $run[2]], $runs);
        }

        return $result;
    }
}
