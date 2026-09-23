<?php

namespace App\Services\OfficialKnowledgeParsing;

final class PdfPageTextNormalizer
{
    public function normalize(string $text): string
    {
        $normalized = preg_replace(
            '/^[^\r\n]*\bTROUBLESHOOTING\s*>\s*\d+\.\s*MALFUNCTION CODE\s*\R?/imu',
            '',
            $text,
        ) ?? $text;

        return preg_replace('/^[ \t]*[A-Z]-\d+[ \t]*(?:\R|$)/mu', '', $normalized) ?? $normalized;
    }
}
