<?php

namespace Tests\Fixtures;

use App\Models\MaintenanceDocument;
use App\Models\MaintenanceOfficialErrorEntry;

final class MaintenanceOfficialKnowledgeFixture
{
    public static function c3913(MaintenanceDocument $document): MaintenanceOfficialErrorEntry
    {
        $steps = [
            'Check that the fusing unit is properly installed, and repair it when there is any abnormality.',
            'Check the connection between the rear side connector of the fusing unit and the main body side connector, and repair it when there is any abnormality.',
            'Check the connector connection and the wiring on PRCB, and repair it when there is any abnormality.',
            'Replace PRCB.',
            'Replace the fusing unit.',
        ];
        $raw = implode("\n", [
            '2.20.31 C-3913',
            'Classification: Main body: Fusing unit placement abnormality',
            'Cause: The fusing unit is not installed.',
            'Estimated abnormal parts: Fusing unit; Printer control board (PRCB)',
            ...array_map(fn (string $step, int $index) => ($index + 1).'. '.$step, $steps, array_keys($steps)),
        ]);

        $entry = MaintenanceOfficialErrorEntry::create([
            'document_id' => $document->id,
            'code' => 'C-3913',
            'variant_key' => 'MAIN_BODY',
            'section_number' => '2.20.31',
            'classification' => 'Main body: Fusing unit placement abnormality',
            'cause' => 'The fusing unit is not installed.',
            'source_page_start' => 101,
            'source_page_end' => 102,
            'raw_source_text' => $raw,
        ]);
        $entry->applicabilities()->create([
            'sequence' => 1,
            'scope_type' => 'MAIN_BODY',
            'scope_label' => 'Main Body',
            'machine_model_id' => $document->machine_model_id,
        ]);
        $entry->parts()->createMany([
            ['sequence' => 1, 'part_name' => 'Fusing unit'],
            ['sequence' => 2, 'part_name' => 'Printer control board', 'part_code' => 'PRCB'],
        ]);
        foreach ($steps as $index => $instruction) {
            $entry->steps()->create(['step_number' => $index + 1, 'instruction' => $instruction]);
        }

        return $entry->fresh();
    }

    /** @return array{0: MaintenanceOfficialErrorEntry, 1: MaintenanceOfficialErrorEntry} */
    public static function c1103Variants(MaintenanceDocument $document): array
    {
        $a = MaintenanceOfficialErrorEntry::create([
            'document_id' => $document->id,
            'code' => 'C-1103',
            'variant_key' => ' fs-531 / fs-612 ',
            'classification' => 'Fixture classification A',
            'cause' => 'Fixture cause A',
            'raw_source_text' => 'Fixture raw source for C-1103 variant A.',
        ]);
        $a->applicabilities()->createMany([
            ['sequence' => 1, 'scope_type' => 'ACCESSORY', 'scope_label' => 'FS-531'],
            ['sequence' => 2, 'scope_type' => 'ACCESSORY', 'scope_label' => 'FS-612'],
        ]);
        $a->parts()->create(['sequence' => 1, 'part_name' => 'Fixture part A']);
        $a->steps()->create(['step_number' => 1, 'instruction' => 'Fixture solution A']);
        $a->references()->create(['step_number' => 1, 'reference_type' => 'OTHER', 'reference_value' => 'Fixture reference A']);

        $b = MaintenanceOfficialErrorEntry::create([
            'document_id' => $document->id,
            'code' => 'C-1103',
            'variant_key' => 'FS-532',
            'classification' => 'Fixture classification B',
            'cause' => 'Fixture cause B',
            'raw_source_text' => 'Fixture raw source for C-1103 variant B.',
        ]);
        $b->applicabilities()->create(['sequence' => 1, 'scope_type' => 'ACCESSORY', 'scope_label' => 'FS-532']);
        $b->parts()->create(['sequence' => 1, 'part_name' => 'Fixture part B']);
        $b->steps()->create(['step_number' => 1, 'instruction' => 'Fixture solution B']);
        $b->references()->create(['step_number' => 1, 'reference_type' => 'OTHER', 'reference_value' => 'Fixture reference B']);

        return [$a->fresh(), $b->fresh()];
    }

    /** @return array<string, MaintenanceOfficialErrorEntry> */
    public static function specialCodeFamilies(MaintenanceDocument $document): array
    {
        $entries = [];
        foreach (['C-C152', 'C-D010'] as $code) {
            $entries[$code] = MaintenanceOfficialErrorEntry::create([
                'document_id' => $document->id,
                'code' => $code,
                'variant_key' => 'FIXTURE',
                'raw_source_text' => "Fixture raw source for {$code}.",
            ]);
        }
        $entries['C-C170'] = MaintenanceOfficialErrorEntry::create([
            'document_id' => $document->id,
            'code' => 'C-C170',
            'variant_key' => 'MINIMAL',
            'raw_source_text' => 'Fixture minimal raw source for C-C170.',
        ]);
        $entries['C-C170']->steps()->create(['step_number' => 1, 'instruction' => 'Fixture minimal solution step.']);

        return $entries;
    }

    public static function complexC3911Shape(MaintenanceDocument $document): MaintenanceOfficialErrorEntry
    {
        $entry = MaintenanceOfficialErrorEntry::create([
            'document_id' => $document->id,
            'code' => 'C-3911',
            'variant_key' => 'COMPLEX_FIXTURE',
            'classification' => 'Fixture complex classification',
            'warning' => 'Fixture safety warning',
            'isolation_dipsw' => 'Fixture DIPSW instruction',
            'source_page_start' => 201,
            'source_page_end' => 204,
            'raw_source_text' => 'Fixture raw source for the complex fourteen-step structure.',
        ]);
        $entry->applicabilities()->create(['sequence' => 1, 'scope_type' => 'MAIN_BODY', 'scope_label' => 'Main Body']);
        $entry->parts()->createMany([
            ['sequence' => 1, 'part_name' => 'Fixture part 01'],
            ['sequence' => 2, 'part_name' => 'Fixture part 02', 'part_code' => 'FIX-02'],
            ['sequence' => 3, 'part_name' => 'Fixture part 03'],
        ]);
        foreach (range(1, 14) as $step) {
            $entry->steps()->create([
                'step_number' => $step,
                'instruction' => sprintf('Fixture step %02d', $step),
                'requires_technician' => $step >= 10,
            ]);
        }
        $entry->references()->createMany([
            ['step_number' => 3, 'reference_type' => 'WIRING_DIAGRAM', 'reference_value' => 'Fixture wiring reference'],
            ['step_number' => 7, 'reference_type' => 'IO_CHECK', 'reference_value' => 'Fixture I/O reference'],
            ['reference_type' => 'DIPSW', 'reference_value' => 'Fixture DIPSW reference'],
        ]);

        return $entry->fresh();
    }
}
