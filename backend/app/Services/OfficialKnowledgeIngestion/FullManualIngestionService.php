<?php

namespace App\Services\OfficialKnowledgeIngestion;

use App\Models\MaintenanceDocument;
use App\Models\MaintenanceOfficialErrorEntry;
use App\Models\MaintenanceOfficialIngestionRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class FullManualIngestionService
{
    public function __construct(
        private readonly OfficialKnowledgeIngestionService $ingestion,
        private readonly OfficialDocumentSourceResolver $resolver,
        private readonly FullManualIntegrityVerifier $verifier,
        private readonly ProtectedMaintenanceState $protectedState,
        private readonly FullManualFailureInjector $failureInjector,
    ) {}

    /** @return array<string, mixed> */
    public function preview(
        MaintenanceDocument $document,
        ResolvedOfficialDocumentSource $source,
        PreparedFullManualDataset $dataset,
        ReviewedFullManualContract $contract,
        string $releaseSha,
    ): array {
        $this->assertReleaseSha($releaseSha);
        $this->assertContract($dataset, $contract);
        $actions = $this->actionCounts($document, $dataset);
        [$scenario, $run] = $this->classifyScenario($document, $dataset, $contract, $actions);
        $protectedState = $this->protectedState->snapshot();

        return [
            'mode' => 'PREVIEW',
            'document_id' => $document->id,
            'source_file_name' => $source->fileName,
            'source_storage_disk' => $source->storageDisk,
            'source_storage_path' => $source->storagePath,
            'source_canonical_path' => $source->canonicalPath,
            'source_pdf_sha256' => $source->sha256,
            'expected_source_pdf_sha256' => $contract::SOURCE_SHA256,
            'parser_revision' => $contract::PARSER_REVISION,
            'extractor_revision' => $contract::EXTRACTOR_REVISION,
            'release_git_sha' => strtolower($releaseSha),
            'contract_name' => $contract::NAME,
            'contract_version' => $contract::VERSION,
            'dataset_digest' => $dataset->datasetDigest,
            'expected_dataset_digest' => $contract::DATASET_DIGEST,
            ...$dataset->metrics,
            ...$actions,
            'expected_post_apply_parent_count' => $contract->expected()['eligible'],
            'scenario' => $scenario,
            'completed_run_id' => $run?->id,
            'excluded' => $dataset->excluded,
            'contract_match' => true,
            'mutation_performed' => false,
            'protected_state' => $protectedState,
        ];
    }

    /** @return array<string, mixed> */
    public function apply(
        MaintenanceDocument $document,
        ResolvedOfficialDocumentSource $source,
        PreparedFullManualDataset $dataset,
        ReviewedFullManualContract $contract,
        string $releaseSha,
    ): array {
        $preview = $this->preview($document, $source, $dataset, $contract, $releaseSha);

        return DB::transaction(function () use ($document, $source, $dataset, $contract, $releaseSha, $preview): array {
            $locked = MaintenanceDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $this->resolver->revalidate($locked, $source, $contract::SOURCE_SHA256);
            $actions = $this->actionCounts($locked, $dataset);
            [$scenario, $existingRun] = $this->classifyScenario($locked, $dataset, $contract, $actions);
            if ($scenario !== $preview['scenario'] || $actions !== $this->actionSubset($preview)) {
                throw new RuntimeException('The action plan changed after the document lock was acquired.');
            }
            if ($this->protectedState->snapshot() !== $preview['protected_state']) {
                throw new RuntimeException('Protected legacy/Documents/Tickets state changed after preview.');
            }

            if ($scenario === 'IDEMPOTENT_RERUN') {
                $verification = $this->verifier->verify($locked, $existingRun, $dataset, $contract);

                return [...$preview, 'mode' => 'APPLY', 'mutation_performed' => false, 'transaction' => 'NO_OP', 'verification' => $verification];
            }

            $expected = $contract->expected();
            $run = MaintenanceOfficialIngestionRun::create([
                'document_id' => $locked->id,
                'source_file_name' => $source->fileName,
                'source_storage_disk' => $source->storageDisk,
                'source_storage_path' => $source->storagePath,
                'source_pdf_sha256' => $source->sha256,
                'parser_revision' => $contract::PARSER_REVISION,
                'extractor_revision' => $contract::EXTRACTOR_REVISION,
                'release_git_sha' => strtolower($releaseSha),
                'contract_name' => $contract::NAME,
                'contract_version' => $contract::VERSION,
                'dataset_digest' => $dataset->datasetDigest,
                'discovered_count' => $expected['discovered'], 'pass_count' => $expected['pass'],
                'warn_count' => $expected['warn'], 'fail_count' => $expected['fail'], 'eligible_count' => $expected['eligible'],
                'distinct_discovered_code_count' => $expected['distinct_discovered_codes'],
                'distinct_eligible_code_count' => $expected['distinct_eligible_codes'],
                'identity_collision_count' => $expected['identity_collisions'],
                'unresolved_applicability_count' => $expected['unresolved_applicability'],
                'unverified_boundary_count' => $expected['unverified_boundaries'],
                'created_count' => $actions['created'], 'unchanged_count' => $actions['unchanged'],
                'updated_count' => $actions['updated'], 'rejected_count' => $actions['rejected'],
                'persisted_parent_count' => $expected['eligible'],
                'persisted_applicability_count' => $expected['applicabilities'],
                'persisted_part_count' => $expected['parts'], 'persisted_step_count' => $expected['steps'],
                'persisted_reference_count' => $expected['references'],
                'warning_entry_count' => $expected['warnings'], 'dipsw_entry_count' => $expected['dipsw'],
                'detached_control_entry_count' => $expected['detached_control'],
                'status' => MaintenanceOfficialIngestionRun::STATUS_APPLYING,
                'started_at' => now(),
            ]);
            $this->failureInjector->checkpoint('early');

            $middle = intdiv(count($dataset->eligibleEntries), 2);
            $results = $this->ingestion->persistReviewedBatch(
                $locked,
                $dataset->eligibleEntries,
                $run->id,
                function (int $completed, int $total) use ($middle): void {
                    if ($completed === $middle) {
                        $this->failureInjector->checkpoint('middle');
                    }
                    if ($completed === $total) {
                        $this->failureInjector->checkpoint('final_child');
                    }
                },
            );
            $persistedActions = array_count_values(array_map(static fn ($result): string => $result->action->value, $results));
            if (($persistedActions['CREATED'] ?? 0) !== $expected['eligible'] || count($persistedActions) !== 1) {
                throw new RuntimeException('Reviewed persistence did not create exactly the expected eligible set.');
            }
            $this->failureInjector->checkpoint('reconciliation');

            $run->update(['status' => MaintenanceOfficialIngestionRun::STATUS_COMPLETED, 'completed_at' => now()]);
            $this->failureInjector->checkpoint('run_completion');
            $verification = $this->verifier->verify($locked, $run->fresh(), $dataset, $contract);
            $this->resolver->revalidate($locked->fresh(), $source, $contract::SOURCE_SHA256);
            if ($this->protectedState->snapshot() !== $preview['protected_state']) {
                throw new RuntimeException('Protected legacy/Documents/Tickets state changed during apply.');
            }

            return [...$preview, 'mode' => 'APPLY', 'mutation_performed' => true, 'transaction' => 'COMMITTED', 'completed_run_id' => $run->id, 'verification' => $verification];
        }, 1);
    }

    /** @return array<string, mixed> */
    public function verify(MaintenanceDocument $document, PreparedFullManualDataset $dataset, ReviewedFullManualContract $contract): array
    {
        $this->assertContract($dataset, $contract);
        $run = $this->matchingCompletedRun($document, $contract);
        if ($run === null) {
            throw new RuntimeException('No matching completed reviewed ingestion run exists.');
        }

        return $this->verifier->verify($document, $run, $dataset, $contract);
    }

    private function assertContract(PreparedFullManualDataset $dataset, ReviewedFullManualContract $contract): void
    {
        foreach ($contract->expected() as $key => $expected) {
            if (($dataset->metrics[$key] ?? null) !== $expected) {
                throw new RuntimeException("Reviewed contract mismatch for {$key}: expected {$expected}, got ".($dataset->metrics[$key] ?? 'missing').'.');
            }
        }
        if (! hash_equals($contract::DATASET_DIGEST, $dataset->datasetDigest)) {
            throw new RuntimeException('Reviewed eligible dataset digest mismatch.');
        }
        if ($dataset->reviewedInventory !== $contract->reviewedInventory()) {
            throw new RuntimeException('Reviewed full-manual identity/source/digest inventory mismatch.');
        }
        $actualExclusions = array_map(static fn (array $entry): array => array_intersect_key($entry, array_flip(['section', 'code', 'variant_key', 'outcome'])), $dataset->excluded);
        if ($actualExclusions !== $contract->exclusions()) {
            throw new RuntimeException('Reviewed non-PASS exclusion set mismatch.');
        }
    }

    /** @return array{created: int, unchanged: int, updated: int, rejected: int} */
    private function actionCounts(MaintenanceDocument $document, PreparedFullManualDataset $dataset): array
    {
        $counts = ['created' => 0, 'unchanged' => 0, 'updated' => 0, 'rejected' => count($dataset->excluded)];
        foreach ($dataset->eligibleEntries as $entry) {
            $action = strtolower($this->ingestion->planReviewed($document, $entry)->action->value);
            $counts[$action]++;
        }

        return $counts;
    }

    /** @return array{string, MaintenanceOfficialIngestionRun|null} */
    private function classifyScenario(MaintenanceDocument $document, PreparedFullManualDataset $dataset, ReviewedFullManualContract $contract, array $actions): array
    {
        $eligible = $contract->expected()['eligible'];
        $rejected = $contract->expected()['warn'] + $contract->expected()['fail'];
        $parents = MaintenanceOfficialErrorEntry::query()->where('document_id', $document->id)->count();
        if ($parents === 0 && $actions === ['created' => $eligible, 'unchanged' => 0, 'updated' => 0, 'rejected' => $rejected]) {
            if ($this->matchingCompletedRun($document, $contract) !== null) {
                throw new RuntimeException('A completed reviewed run exists without its official parents.');
            }

            return ['FIRST_APPLY', null];
        }

        $run = $this->matchingCompletedRun($document, $contract);
        if ($parents === $eligible && $actions === ['created' => 0, 'unchanged' => $eligible, 'updated' => 0, 'rejected' => $rejected] && $run !== null) {
            $linked = MaintenanceOfficialErrorEntry::query()->where('document_id', $document->id)->where('ingestion_run_id', $run->id)->count();
            if ($linked === $eligible) {
                return ['IDEMPOTENT_RERUN', $run];
            }
        }

        throw new RuntimeException('Unexpected existing official state or UPDATED action; reviewed v1.5 apply is refused.');
    }

    private function matchingCompletedRun(MaintenanceDocument $document, ReviewedFullManualContract $contract): ?MaintenanceOfficialIngestionRun
    {
        return MaintenanceOfficialIngestionRun::query()
            ->where('document_id', $document->id)->where('contract_name', $contract::NAME)
            ->where('source_pdf_sha256', $contract::SOURCE_SHA256)->where('dataset_digest', $contract::DATASET_DIGEST)
            ->where('status', MaintenanceOfficialIngestionRun::STATUS_COMPLETED)->first();
    }

    private function assertReleaseSha(string $releaseSha): void
    {
        if (! preg_match('/^[0-9a-f]{40}$/', $releaseSha)) {
            throw new RuntimeException('The configured release Git SHA must be exact lowercase 40-hex.');
        }
    }

    /** @return array{created: int, unchanged: int, updated: int, rejected: int} */
    private function actionSubset(array $preview): array
    {
        return ['created' => $preview['created'], 'unchanged' => $preview['unchanged'], 'updated' => $preview['updated'], 'rejected' => $preview['rejected']];
    }
}
