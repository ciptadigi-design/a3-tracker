<?php

namespace App\Services\OfficialKnowledgeIngestion;

final class ControlledOfficialKnowledgeManifestProvider implements OfficialKnowledgeManifestProvider
{
    public const KONICA_C1070_V15_EIGHT = 'konica-c1070-v1.5-eight';

    public const KONICA_C1070_V15_BOUNDED_FIFTY = 'konica-c1070-v1.5-bounded-fifty';

    public const KONICA_C1070_V15_BOUNDED_PASS = 'konica-c1070-v1.5-bounded-pass-49';

    public function find(string $name): ?OfficialKnowledgeManifest
    {
        return match ($name) {
            self::KONICA_C1070_V15_EIGHT => new OfficialKnowledgeManifest(
                self::KONICA_C1070_V15_EIGHT,
                $this->expectedSha256(),
                $this->goldenEight(),
            ),
            self::KONICA_C1070_V15_BOUNDED_FIFTY => new OfficialKnowledgeManifest(
                self::KONICA_C1070_V15_BOUNDED_FIFTY,
                $this->expectedSha256(),
                $this->boundedFifty(),
            ),
            self::KONICA_C1070_V15_BOUNDED_PASS => new OfficialKnowledgeManifest(
                self::KONICA_C1070_V15_BOUNDED_PASS,
                $this->expectedSha256(),
                array_values(array_filter(
                    $this->boundedFifty(),
                    fn (OfficialKnowledgeSelector $selector): bool => $selector->sectionNumber !== '2.26.21',
                )),
            ),
            default => null,
        };
    }

    private function expectedSha256(): string
    {
        return 'ae679f5bcd31a49826f9b2587ad4b9483be93950f246c1ea014a7dd0ddcb6ee4';
    }

    /** @return list<OfficialKnowledgeSelector> */
    private function goldenEight(): array
    {
        return [
            new OfficialKnowledgeSelector('2.20.31', 'C-3913', 'MAIN_BODY', [1606]),
            new OfficialKnowledgeSelector('2.8.13', 'C-1103', 'FS-531_FS-612', [1450, 1451]),
            new OfficialKnowledgeSelector('2.8.14', 'C-1103', 'FS-532', [1451]),
            new OfficialKnowledgeSelector('2.20.29', 'C-3911', 'MAIN_BODY', [1605]),
            new OfficialKnowledgeSelector('2.11.32', 'C-1334', 'PB', [1513]),
            new OfficialKnowledgeSelector('2.25.26', 'C-C152', 'MAIN_BODY', [1655]),
            new OfficialKnowledgeSelector('2.26.1', 'C-D010', 'MAIN_BODY', [1656]),
            new OfficialKnowledgeSelector('2.26.31', 'C-E012', 'MAIN_BODY', [1669]),
        ];
    }

    /**
     * Deterministic, stratified validation sample. It deliberately spans numeric and letter-bearing
     * code families, main-body and accessory scopes, repeated-code variants, page boundaries,
     * minimal records, and long/safety-bearing procedures. It contains identifiers only.
     *
     * @return list<OfficialKnowledgeSelector>
     */
    private function boundedFifty(): array
    {
        return [
            ...$this->goldenEight(),

            // Numeric main-body records distributed across the malfunction chapter.
            new OfficialKnowledgeSelector('2.5.1', 'C-0001', 'MAIN_BODY', [1412]),
            new OfficialKnowledgeSelector('2.5.2', 'C-0002', 'MAIN_BODY', [1412, 1413]),
            new OfficialKnowledgeSelector('2.5.13', 'C-0201', 'MAIN_BODY', [1417]),
            new OfficialKnowledgeSelector('2.6.1', 'C-0301', 'MAIN_BODY', [1425]),
            new OfficialKnowledgeSelector('2.7.1', 'C-0401', 'LU', [1438]),
            new OfficialKnowledgeSelector('2.8.1', 'C-1005', 'FS', [1446]),

            // Accessory scopes and repeated-code variants.
            new OfficialKnowledgeSelector('2.8.9', 'C-1014', 'RU', [1449]),
            new OfficialKnowledgeSelector('2.8.10', 'C-1101', 'FS-531_FS-612', [1449]),
            new OfficialKnowledgeSelector('2.8.16', 'C-1105', 'FS-531_FS-612', [1451, 1452]),
            new OfficialKnowledgeSelector('2.8.17', 'C-1105', 'FS-532', [1452]),
            new OfficialKnowledgeSelector('2.8.22', 'C-1109', 'FS-531_FS-612', [1454]),
            new OfficialKnowledgeSelector('2.8.23', 'C-1109', 'FS-532', [1454, 1455]),
            new OfficialKnowledgeSelector('2.8.36', 'C-1126', 'PI', [1460]),
            new OfficialKnowledgeSelector('2.9.1', 'C-1127', 'PK-512_PK-513', [1460, 1461]),
            new OfficialKnowledgeSelector('2.9.2', 'C-1127', 'PK-522', [1461]),
            new OfficialKnowledgeSelector('2.9.3', 'C-1132', 'PK-512_PK-513', [1461, 1462]),
            new OfficialKnowledgeSelector('2.9.4', 'C-1132', 'PK-522', [1462]),
            new OfficialKnowledgeSelector('2.9.7', 'C-1141', 'FS-532', [1463, 1464]),
            new OfficialKnowledgeSelector('2.9.29', 'C-1202', 'LS_1ST_TANDEM', [1473, 1474]),
            new OfficialKnowledgeSelector('2.11.1', 'C-1271', 'SD', [1500, 1501]),
            new OfficialKnowledgeSelector('2.11.27', 'C-1311', 'SD', [1511]),
            new OfficialKnowledgeSelector('2.11.28', 'C-1330', 'PB', [1511]),
            new OfficialKnowledgeSelector('2.11.33', 'C-1341', 'RU', [1513]),
            new OfficialKnowledgeSelector('2.12.1', 'C-1402', 'FS', [1518, 1519]),
            new OfficialKnowledgeSelector('2.13.1', 'C-1501', 'PB', [1526, 1527]),

            // Later numeric families include short, long, reference-rich, and safety-bearing layouts.
            new OfficialKnowledgeSelector('2.15.1', 'C-2001', 'MAIN_BODY', [1555]),
            new OfficialKnowledgeSelector('2.16.1', 'C-2401', 'MAIN_BODY', [1563, 1564]),
            new OfficialKnowledgeSelector('2.17.1', 'C-2701', 'MAIN_BODY', [1571, 1572]),
            new OfficialKnowledgeSelector('2.18.1', 'C-2801', 'MAIN_BODY', [1575, 1576]),
            new OfficialKnowledgeSelector('2.19.1', 'C-3101', 'MAIN_BODY', [1583]),
            new OfficialKnowledgeSelector('2.20.5', 'C-3508', 'MAIN_BODY', [1589, 1590]),
            new OfficialKnowledgeSelector('2.20.15', 'C-3801', 'MAIN_BODY', [1596, 1597]),
            new OfficialKnowledgeSelector('2.20.30', 'C-3912', 'MAIN_BODY', [1605, 1606]),
            new OfficialKnowledgeSelector('2.21.1', 'C-4101', 'MAIN_BODY', [1607]),
            new OfficialKnowledgeSelector('2.22.1', 'C-5001', 'MAIN_BODY', [1630]),
            new OfficialKnowledgeSelector('2.23.1', 'C-6102', 'MAIN_BODY', [1637, 1638]),
            new OfficialKnowledgeSelector('2.24.1', 'C-7001', 'MAIN_BODY', [1643]),
            new OfficialKnowledgeSelector('2.24.2', 'C-8001', 'DF', [1643]),
            new OfficialKnowledgeSelector('2.24.12', 'C-9402', 'MAIN_BODY', [1647]),

            // Letter-bearing families plus genuinely sparse manufacturer records.
            new OfficialKnowledgeSelector('2.25.1', 'C-C101', 'MAIN_BODY', [1647, 1648]),
            new OfficialKnowledgeSelector('2.25.28', 'C-C170', 'MAIN_BODY', [1656]),
            new OfficialKnowledgeSelector('2.26.21', 'C-D0F8', 'MAIN_BODY', [1665]),
        ];
    }
}
