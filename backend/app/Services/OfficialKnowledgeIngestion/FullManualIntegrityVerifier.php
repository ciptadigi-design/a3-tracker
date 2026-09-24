<?php

namespace App\Services\OfficialKnowledgeIngestion;

use App\Models\MaintenanceDocument;
use App\Models\MaintenanceOfficialErrorEntry;
use App\Models\MaintenanceOfficialIngestionRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class FullManualIntegrityVerifier
{
    public function __construct(private readonly OfficialKnowledgeCanonicalizer $canonicalizer) {}

    /** @return array<string, int|string|bool> */
    public function verify(
        MaintenanceDocument $document,
        MaintenanceOfficialIngestionRun $run,
        PreparedFullManualDataset $dataset,
        ReviewedFullManualContract $contract,
    ): array {
        if ($run->status !== MaintenanceOfficialIngestionRun::STATUS_COMPLETED) {
            throw new RuntimeException('The reviewed ingestion run is not COMPLETED.');
        }
        if ($run->document_id !== $document->id
            || $run->contract_name !== ReviewedFullManualContract::NAME
            || ! hash_equals($contract::SOURCE_SHA256, $run->source_pdf_sha256)
            || ! hash_equals($contract::DATASET_DIGEST, $run->dataset_digest)) {
            throw new RuntimeException('The completed run provenance does not match the reviewed contract.');
        }
        $matchingRuns = MaintenanceOfficialIngestionRun::query()
            ->where('document_id', $document->id)
            ->where('contract_name', $contract::NAME)
            ->where('source_pdf_sha256', $contract::SOURCE_SHA256)
            ->where('dataset_digest', $contract::DATASET_DIGEST)
            ->where('status', MaintenanceOfficialIngestionRun::STATUS_COMPLETED)
            ->count();
        $this->expect('completed matching run count', 1, $matchingRuns);
        $expected = $contract->expected();
        $runValues = [
            'parser_revision' => $contract::PARSER_REVISION,
            'extractor_revision' => $contract::EXTRACTOR_REVISION,
            'contract_version' => $contract::VERSION,
            'discovered_count' => $expected['discovered'], 'pass_count' => $expected['pass'],
            'warn_count' => $expected['warn'], 'fail_count' => $expected['fail'], 'eligible_count' => $expected['eligible'],
            'distinct_discovered_code_count' => $expected['distinct_discovered_codes'],
            'distinct_eligible_code_count' => $expected['distinct_eligible_codes'],
            'identity_collision_count' => $expected['identity_collisions'],
            'unresolved_applicability_count' => $expected['unresolved_applicability'],
            'unverified_boundary_count' => $expected['unverified_boundaries'],
            'created_count' => $expected['eligible'], 'unchanged_count' => 0, 'updated_count' => 0,
            'rejected_count' => $expected['warn'] + $expected['fail'],
            'persisted_parent_count' => $expected['eligible'],
            'persisted_applicability_count' => $expected['applicabilities'],
            'persisted_part_count' => $expected['parts'], 'persisted_step_count' => $expected['steps'],
            'persisted_reference_count' => $expected['references'],
            'warning_entry_count' => $expected['warnings'], 'dipsw_entry_count' => $expected['dipsw'],
            'detached_control_entry_count' => $expected['detached_control'],
        ];
        foreach ($runValues as $column => $value) {
            if ($run->{$column} !== $value) {
                throw new RuntimeException("Completed run {$column} does not match the reviewed contract.");
            }
        }
        if (! preg_match('/^[0-9a-f]{40}$/', (string) $run->release_git_sha) || $run->completed_at === null) {
            throw new RuntimeException('Completed run release/timestamp provenance is invalid.');
        }

        $entries = MaintenanceOfficialErrorEntry::query()
            ->where('document_id', $document->id)
            ->with(['applicabilities', 'parts', 'steps', 'references'])
            ->get();
        $this->expect('parent count', $expected['eligible'], $entries->count());
        $this->expect('run-linked parent count', $expected['eligible'], $entries->where('ingestion_run_id', $run->id)->count());
        $this->expect('distinct eligible code count', $expected['distinct_eligible_codes'], $entries->pluck('code')->unique()->count());
        $this->expect('applicability count', $expected['applicabilities'], $entries->sum(fn ($entry): int => $entry->applicabilities->count()));
        $this->expect('part count', $expected['parts'], $entries->sum(fn ($entry): int => $entry->parts->count()));
        $this->expect('step count', $expected['steps'], $entries->sum(fn ($entry): int => $entry->steps->count()));
        $this->expect('reference count', $expected['references'], $entries->sum(fn ($entry): int => $entry->references->count()));

        $byIdentity = $entries->keyBy(fn ($entry): string => $entry->code."\0".$entry->variant_key);
        $this->expect('identity count', $expected['eligible'], $byIdentity->count());
        foreach ($dataset->eligibleEntries as $parsed) {
            $identity = strtoupper(trim($parsed->code))."\0".MaintenanceOfficialErrorEntry::normalizeVariantKey($parsed->variantKey);
            $entry = $byIdentity->get($identity);
            if ($entry === null) {
                throw new RuntimeException("Missing reviewed identity {$parsed->code}/{$parsed->variantKey}.");
            }
            $digest = $this->canonicalizer->entryDigest($parsed);
            if ($entry->normalized_digest === null || ! hash_equals($digest, $entry->normalized_digest)) {
                throw new RuntimeException("Normalized digest mismatch for {$parsed->code}/{$parsed->variantKey}.");
            }
            if (! hash_equals($parsed->sourceHash, (string) $entry->source_hash)
                || $entry->source_page_start !== $parsed->sourcePageStart
                || $entry->source_page_end !== $parsed->sourcePageEnd) {
                throw new RuntimeException("Source provenance mismatch for {$parsed->code}/{$parsed->variantKey}.");
            }
            $this->verifyPersistedSemantics($entry, $parsed);
        }

        $duplicates = MaintenanceOfficialErrorEntry::query()
            ->select(['document_id', 'code', 'variant_key'])
            ->where('document_id', $document->id)
            ->groupBy(['document_id', 'code', 'variant_key'])
            ->havingRaw('COUNT(*) > 1')->count();
        $this->expect('duplicate identity count', 0, $duplicates);
        foreach (['applicabilities', 'parts', 'steps', 'references'] as $child) {
            $table = 'maintenance_official_error_'.$child;
            $orphans = DB::table($table.' as child')
                ->leftJoin('maintenance_official_error_entries as parent', 'parent.id', '=', 'child.error_entry_id')
                ->whereNull('parent.id')->count();
            $this->expect("{$child} orphan count", 0, $orphans);
        }
        foreach ($entries as $entry) {
            $numbers = $entry->steps->pluck('step_number')->all();
            if ($numbers !== ($numbers === [] ? [] : range(1, count($numbers)))) {
                throw new RuntimeException("Invalid step sequence for {$entry->code}/{$entry->variant_key}.");
            }
        }
        $this->expect('warning-bearing count', $expected['warnings'], $entries->whereNotNull('warning')->count());
        $this->expect('detached-control-bearing count', $expected['detached_control'], $entries->whereNotNull('detached_control')->count());
        $this->expect('DIPSW-bearing count', $expected['dipsw'], $entries->filter(fn ($entry): bool => $this->dbEntryIsDipswBearing($entry))->count());
        $variants = $entries->where('code', 'C-1103')->pluck('variant_key')->sort()->values()->all();
        if ($variants !== ['FS-531_FS-612', 'FS-532']) {
            throw new RuntimeException('C-1103 reviewed variants do not match.');
        }
        if (! hash_equals($contract::DATASET_DIGEST, $dataset->datasetDigest)) {
            throw new RuntimeException('Reviewed eligible dataset digest mismatch during verification.');
        }

        return [
            'verified' => true,
            'parents' => $entries->count(),
            'distinct_codes' => $entries->pluck('code')->unique()->count(),
            'identities' => $byIdentity->count(),
            'applicabilities' => $entries->sum(fn ($entry): int => $entry->applicabilities->count()),
            'parts' => $entries->sum(fn ($entry): int => $entry->parts->count()),
            'steps' => $entries->sum(fn ($entry): int => $entry->steps->count()),
            'references' => $entries->sum(fn ($entry): int => $entry->references->count()),
            'duplicate_identities' => 0,
            'orphan_children' => 0,
            'invalid_step_sequences' => 0,
            'dataset_digest' => $dataset->datasetDigest,
        ];
    }

    private function verifyPersistedSemantics($entry, $parsed): void
    {
        $parentMap = [
            'section_number' => 'sectionNumber', 'classification' => 'classification', 'cause' => 'cause',
            'alert_measure' => 'alertMeasure', 'correction' => 'correction', 'warning' => 'warning',
            'note' => 'note', 'isolation_dipsw' => 'isolationDipsw', 'detached_control' => 'detachedControl',
            'raw_source_text' => 'rawSourceText',
        ];
        foreach ($parentMap as $column => $property) {
            if ($entry->{$column} !== $parsed->{$property}) {
                throw new RuntimeException("Persisted {$column} mismatch for {$parsed->code}/{$parsed->variantKey}.");
            }
        }
        if ($entry->applicabilities->pluck('scope_label')->all() !== $parsed->applicabilities
            || $entry->parts->pluck('part_name')->all() !== $parsed->parts
            || $entry->steps->pluck('step_number')->all() !== array_column($parsed->steps, 'number')
            || $entry->steps->pluck('instruction')->all() !== array_column($parsed->steps, 'instruction')) {
            throw new RuntimeException("Persisted ordered children mismatch for {$parsed->code}/{$parsed->variantKey}.");
        }
        $databaseReferences = $entry->references->map(fn ($reference): array => [
            'step_number' => $reference->step_number,
            'type' => $reference->reference_type,
            'value' => $reference->reference_value,
            'page_number' => $reference->page_number,
            'section_number' => $reference->section_number,
        ])->sortBy(fn (array $reference): string => json_encode($reference, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->values()->all();
        $parsedReferences = array_map(static fn ($reference): array => [
            'step_number' => $reference->stepNumber,
            'type' => $reference->type,
            'value' => $reference->value,
            'page_number' => $reference->pageNumber,
            'section_number' => $reference->sectionNumber,
        ], $parsed->references);
        usort($parsedReferences, static fn (array $a, array $b): int => strcmp(json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        if ($databaseReferences !== $parsedReferences) {
            throw new RuntimeException("Persisted references mismatch for {$parsed->code}/{$parsed->variantKey}.");
        }
    }

    private function dbEntryIsDipswBearing($entry): bool
    {
        $values = [
            $entry->classification, $entry->cause, $entry->alert_measure, $entry->correction,
            $entry->warning, $entry->note, $entry->isolation_dipsw, $entry->detached_control,
            ...$entry->steps->pluck('instruction')->all(),
        ];

        return preg_match('/DIPSW/iu', implode("\n", array_filter($values, static fn ($value): bool => $value !== null))) === 1;
    }

    private function expect(string $label, int $expected, int $actual): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException("Integrity {$label} mismatch: expected {$expected}, got {$actual}.");
        }
    }
}
