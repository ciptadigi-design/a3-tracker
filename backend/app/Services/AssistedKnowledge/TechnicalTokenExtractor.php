<?php

namespace App\Services\AssistedKnowledge;

class TechnicalTokenExtractor
{
    public function extract(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        preg_match_all('/(?<![A-Z0-9])(?:C-[A-Z0-9]+|[A-Z]{1,8}\d+[A-Z0-9]*(?:-[A-Z0-9]+)*|[A-Z]{2,8}-\d+[A-Z0-9-]*|[A-Z]{2,8}|DIPSW\d+(?:-\d+)?|I\/O|\d+(?:\.\d+)?\s?(?:°C|mV|mA|V|A|mm|cm|%))(?![A-Z0-9])/u', $text, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    public function missing(?string $official, ?string $assisted): array
    {
        return array_values(array_filter(
            $this->extract($official),
            fn (string $token) => $assisted === null || ! str_contains($assisted, $token),
        ));
    }
}
