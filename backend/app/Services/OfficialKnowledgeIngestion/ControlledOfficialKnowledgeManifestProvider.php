<?php

namespace App\Services\OfficialKnowledgeIngestion;

final class ControlledOfficialKnowledgeManifestProvider implements OfficialKnowledgeManifestProvider
{
    public const KONICA_C1070_V15_EIGHT = 'konica-c1070-v1.5-eight';

    public function find(string $name): ?OfficialKnowledgeManifest
    {
        if ($name !== self::KONICA_C1070_V15_EIGHT) {
            return null;
        }

        return new OfficialKnowledgeManifest(
            self::KONICA_C1070_V15_EIGHT,
            'ae679f5bcd31a49826f9b2587ad4b9483be93950f246c1ea014a7dd0ddcb6ee4',
            [
                new OfficialKnowledgeSelector('2.20.31', 'C-3913', 'MAIN_BODY', [1606]),
                new OfficialKnowledgeSelector('2.8.13', 'C-1103', 'FS-531_FS-612', [1450, 1451]),
                new OfficialKnowledgeSelector('2.8.14', 'C-1103', 'FS-532', [1451]),
                new OfficialKnowledgeSelector('2.20.29', 'C-3911', 'MAIN_BODY', [1605]),
                new OfficialKnowledgeSelector('2.11.32', 'C-1334', 'PB', [1513]),
                new OfficialKnowledgeSelector('2.25.26', 'C-C152', 'MAIN_BODY', [1655]),
                new OfficialKnowledgeSelector('2.26.1', 'C-D010', 'MAIN_BODY', [1656]),
                new OfficialKnowledgeSelector('2.26.31', 'C-E012', 'MAIN_BODY', [1669]),
            ],
        );
    }
}
