<?php

namespace App\Services\OfficialKnowledgeParsing;

final readonly class ParserDiagnostic
{
    /** @param array<string, int|string|null> $context */
    public function __construct(
        public ParserDiagnosticCode $code,
        public ParserDiagnosticSeverity $severity,
        public string $message,
        public array $context = [],
    ) {}
}
