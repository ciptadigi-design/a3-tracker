<?php

namespace App\Services\OfficialKnowledgeParsing;

final readonly class SourceTextChunk
{
    public function __construct(
        public string $text,
        public ?int $pageNumber = null,
    ) {}
}
