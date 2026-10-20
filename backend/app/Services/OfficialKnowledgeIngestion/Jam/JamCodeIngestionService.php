<?php

namespace App\Services\OfficialKnowledgeIngestion\Jam;

use App\Models\MaintenanceDocument;
use App\Models\MaintenanceOfficialErrorEntry;
use App\Models\MaintenanceOfficialIngestionRun;
use App\Services\OfficialKnowledgeIngestion\OfficialDocumentSourceResolver;
use App\Services\OfficialKnowledgeIngestion\OfficialKnowledgeIngestionAction;
use App\Services\OfficialKnowledgeIngestion\OfficialKnowledgeIngestionService;
use App\Services\OfficialKnowledgeIngestion\ProtectedMaintenanceState;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Additive J-code import into a document that already carries its C-code parents. It never
 * updates or deletes anything: the only accepted states are "no J entries yet" (create all) and
 * "exactly the reviewed J entries already present" (verified no-op).
 */
final class JamCodeIngestionService
{
    public function __construct(
        private readonly OfficialKnowledgeIngestionService $ingestion,
        private readonly OfficialDocumentSourceResolver $resolver,
        private readonly ProtectedMaintenanceState $protectedState,
    ) {}

    /** @param array<string, mixed> $dataset result of JamCodeDatasetPreparer::prepare @return array<string, mixed> */
    public function preview(MaintenanceDocument $document, array $dataset, JamCodeContract $contract, string $sourceSha256): array
    {
        $this->assertContract($dataset, $contract, $sourceSha256);
        [$scenario, $actions] = $this->classify($document, $dataset);

        return [
            'mode' => 'PREVIEW',
            'document_id' => $document->id,
            'contract_name' => $contract->name,
            'source_pdf_sha256' => $sourceSha256,
            'dataset_digest' => $dataset['digest'],
            ...$dataset['metrics'],
            ...$actions,
            'scenario' => $scenario,
            'quarantined_rows' => $dataset['quarantine'],
            'c_code_parents' => $this->preservedFingerprint($document)['count'],
            'contract_match' => true,
            'mutation_performed' => false,
        ];
    }

    /** @param array<string, mixed> $dataset @return array<string, mixed> */
    public function apply(MaintenanceDocument $document, $source, array $dataset, JamCodeContract $contract, string $releaseSha): array
    {
        $preview = $this->preview($document, $dataset, $contract, $source->sha256);
        if (! preg_match('/^[0-9a-f]{40}$/', $releaseSha)) {
            throw new RuntimeException('The configured release Git SHA must be exact lowercase 40-hex.');
        }

        return DB::transaction(function () use ($document, $source, $dataset, $contract, $releaseSha, $preview): array {
            $locked = MaintenanceDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $this->resolver->revalidate($locked, $source, $contract->sourceSha256);
            [$scenario, $actions] = $this->classify($locked, $dataset);
            if ($scenario !== $preview['scenario']) {
                throw new RuntimeException('The action plan changed after the document lock was acquired.');
            }
            $before = $this->preservedFingerprint($locked);
            $protected = $this->protectedState->snapshot();

            if ($scenario === 'IDEMPOTENT_RERUN') {
                return [...$preview, 'mode' => 'APPLY', 'transaction' => 'NO_OP', 'verification' => $this->verifyState($locked, $dataset, $contract)];
            }

            $m = $dataset['metrics'];
            $run = MaintenanceOfficialIngestionRun::create([
                'document_id' => $locked->id, 'source_file_name' => $source->fileName, 'source_storage_disk' => $source->storageDisk,
                'source_storage_path' => $source->storagePath, 'source_pdf_sha256' => $source->sha256,
                'parser_revision' => JamCodeContract::PARSER_REVISION, 'extractor_revision' => JamCodeContract::EXTRACTOR_REVISION,
                'release_git_sha' => $releaseSha, 'contract_name' => $contract->name, 'contract_version' => JamCodeContract::VERSION,
                'dataset_digest' => $dataset['digest'],
                'discovered_count' => $m['discovered'], 'pass_count' => $m['accepted'], 'warn_count' => 0, 'fail_count' => $m['quarantined'],
                'eligible_count' => $m['accepted'], 'distinct_discovered_code_count' => $m['distinct_discovered_codes'],
                'distinct_eligible_code_count' => $m['distinct_codes'], 'identity_collision_count' => 0,
                'unresolved_applicability_count' => 0, 'unverified_boundary_count' => 0,
                'created_count' => $actions['created'], 'unchanged_count' => 0, 'updated_count' => 0, 'rejected_count' => $m['quarantined'],
                'persisted_parent_count' => $m['accepted'], 'persisted_applicability_count' => $m['applicabilities'],
                'persisted_part_count' => 0, 'persisted_step_count' => $m['steps'], 'persisted_reference_count' => 0,
                'warning_entry_count' => 0, 'dipsw_entry_count' => 0, 'detached_control_entry_count' => 0,
                'status' => MaintenanceOfficialIngestionRun::STATUS_APPLYING, 'started_at' => now(),
            ]);

            $results = $this->ingestion->persistReviewedBatch($locked, $dataset['entries'], $run->id);
            foreach ($results as $result) {
                if ($result->action !== OfficialKnowledgeIngestionAction::CREATED) {
                    throw new RuntimeException('Additive jam-code persistence must only create entries.');
                }
            }
            $run->update(['status' => MaintenanceOfficialIngestionRun::STATUS_COMPLETED, 'completed_at' => now()]);

            $verification = $this->verifyState($locked, $dataset, $contract);
            if ($this->preservedFingerprint($locked) !== $before) {
                throw new RuntimeException('Existing (non-jam) official entries changed during the additive import.');
            }
            if ($this->protectedState->snapshot() !== $protected) {
                throw new RuntimeException('Protected legacy/Documents/Tickets state changed during the additive import.');
            }

            return [...$preview, 'mode' => 'APPLY', 'mutation_performed' => true, 'transaction' => 'COMMITTED', 'completed_run_id' => $run->id, 'verification' => $verification];
        }, 1);
    }

    /** @param array<string, mixed> $dataset @return array<string, mixed> */
    public function verify(MaintenanceDocument $document, array $dataset, JamCodeContract $contract, string $sourceSha256): array
    {
        $this->assertContract($dataset, $contract, $sourceSha256);

        return $this->verifyState($document, $dataset, $contract);
    }

    /** @param array<string, mixed> $dataset */
    private function assertContract(array $dataset, JamCodeContract $contract, string $sourceSha256): void
    {
        if (! hash_equals($contract->sourceSha256, $sourceSha256)) {
            throw new RuntimeException('The source PDF does not match the reviewed jam-code contract.');
        }
        foreach ($contract->expected as $key => $expected) {
            if (($dataset['metrics'][$key] ?? null) !== $expected) {
                throw new RuntimeException("Reviewed jam-code contract mismatch for {$key}: expected {$expected}, got ".($dataset['metrics'][$key] ?? 'missing').'.');
            }
        }
        if (! hash_equals($contract->datasetDigest, $dataset['digest'])) {
            throw new RuntimeException('Reviewed jam-code dataset digest mismatch.');
        }
        if ($dataset['inventory'] !== $contract->inventory) {
            throw new RuntimeException('Reviewed jam-code identity/page/digest inventory mismatch.');
        }
        if ($dataset['quarantine'] !== $contract->quarantine) {
            throw new RuntimeException('Reviewed jam-code quarantine set mismatch.');
        }
    }

    /** @param array<string, mixed> $dataset @return array{0: string, 1: array{created: int, unchanged: int, updated: int}} */
    private function classify(MaintenanceDocument $document, array $dataset): array
    {
        $counts = ['created' => 0, 'unchanged' => 0, 'updated' => 0];
        foreach ($dataset['entries'] as $entry) {
            $counts[strtolower($this->ingestion->planReviewed($document, $entry)->action->value)]++;
        }
        $total = count($dataset['entries']);
        $existingJam = MaintenanceOfficialErrorEntry::query()->where('document_id', $document->id)->where('code', 'like', 'J-%')->count();

        if ($existingJam === 0 && $counts['created'] === $total) {
            return ['FIRST_APPLY', $counts];
        }
        if ($existingJam === $total && $counts['unchanged'] === $total) {
            return ['IDEMPOTENT_RERUN', $counts];
        }

        throw new RuntimeException('Unexpected existing jam-code state or UPDATE action; the additive import is refused.');
    }

    /** @return array{count: int, sha256: string} everything that is not a J entry, with its stored digest */
    private function preservedFingerprint(MaintenanceDocument $document): array
    {
        $rows = DB::table('maintenance_official_error_entries')
            ->where('document_id', $document->id)->where('code', 'not like', 'J-%')
            ->orderBy('id')->get(['id', 'code', 'variant_key', 'normalized_digest', 'source_hash', 'ingestion_run_id'])
            ->map(fn ($row): string => json_encode((array) $row))->all();

        return ['count' => count($rows), 'sha256' => hash('sha256', implode("\n", $rows))];
    }

    /** @param array<string, mixed> $dataset @return array<string, mixed> */
    private function verifyState(MaintenanceDocument $document, array $dataset, JamCodeContract $contract): array
    {
        $run = MaintenanceOfficialIngestionRun::query()
            ->where('document_id', $document->id)->where('contract_name', $contract->name)
            ->where('dataset_digest', $dataset['digest'])->where('status', MaintenanceOfficialIngestionRun::STATUS_COMPLETED)->first();
        if ($run === null) {
            throw new RuntimeException('No matching completed jam-code ingestion run exists.');
        }
        $entries = MaintenanceOfficialErrorEntry::query()->where('document_id', $document->id)->where('code', 'like', 'J-%')
            ->withCount(['steps', 'applicabilities'])->get();
        $actual = $entries->map(fn ($entry): array => [
            'code' => $entry->code, 'variant_key' => $entry->variant_key,
            'page_start' => $entry->source_page_start, 'page_end' => $entry->source_page_end, 'entry_digest' => $entry->normalized_digest,
        ])->sortBy(fn (array $row): string => $row['code']."\0".$row['variant_key'])->values()->all();
        if ($actual !== $dataset['inventory']) {
            throw new RuntimeException('Persisted jam-code entries do not match the reviewed inventory.');
        }
        if ($entries->contains(fn ($entry): bool => $entry->ingestion_run_id !== $run->id || $entry->steps_count !== 1 || $entry->applicabilities_count !== 1)) {
            throw new RuntimeException('Persisted jam-code children or run linkage are inconsistent.');
        }

        return ['status' => 'PASS', 'run_id' => $run->id, 'entries' => $entries->count(), 'c_code_parents_preserved' => $this->preservedFingerprint($document)['count']];
    }
}
