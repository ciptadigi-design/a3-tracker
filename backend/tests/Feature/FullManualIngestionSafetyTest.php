<?php

namespace Tests\Feature;

use App\Http\Resources\MaintenanceOfficialErrorEntryResource;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceOfficialErrorEntry;
use App\Services\OfficialKnowledgeIngestion\FullManualDatasetPreparer;
use App\Services\OfficialKnowledgeIngestion\FullManualFailureInjector;
use App\Services\OfficialKnowledgeIngestion\FullManualIngestionService;
use App\Services\OfficialKnowledgeIngestion\FullManualIntegrityVerifier;
use App\Services\OfficialKnowledgeIngestion\OfficialDocumentSourceResolver;
use App\Services\OfficialKnowledgeIngestion\OfficialKnowledgeIngestionService;
use App\Services\OfficialKnowledgeIngestion\ProtectedMaintenanceState;
use App\Services\OfficialKnowledgeIngestion\ResolvedOfficialDocumentSource;
use App\Services\OfficialKnowledgeIngestion\ReviewedFullManualContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class FullManualIngestionSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_reviewed_pdf_preview_failure_rollback_apply_rerun_verifier_and_isolation(): void
    {
        $pdf = (string) getenv('MAINTENANCE_V21_PDF');
        if ($pdf === '' || ! is_file($pdf)) {
            $this->markTestSkipped('Set MAINTENANCE_V21_PDF to the reviewed authoritative PDF for the real-PDF rehearsal.');
        }

        $canonicalPdf = realpath($pdf);
        $this->assertIsString($canonicalPdf);
        $beforePdf = $this->fileIdentity($canonicalPdf);
        $contract = new ReviewedFullManualContract;
        $this->assertSame($contract::SOURCE_SHA256, hash_file('sha256', $canonicalPdf));

        config([
            'filesystems.disks.v21_rehearsal' => ['driver' => 'local', 'root' => dirname($canonicalPdf), 'throw' => true],
            'release.git_sha' => str_repeat('a', 40),
        ]);
        $document = MaintenanceDocument::create([
            'title' => 'Disposable V2.1 full-manual rehearsal',
            'document_type' => 'SERVICE_MANUAL',
            'file_reference' => basename($canonicalPdf),
            'file_path' => basename($canonicalPdf),
            'file_name' => basename($canonicalPdf),
            'file_size' => filesize($canonicalPdf),
            'mime_type' => 'application/pdf',
            'storage_disk' => 'v21_rehearsal',
            'status' => 'PUBLISHED',
            'is_active' => true,
        ]);

        $resolver = $this->app->make(OfficialDocumentSourceResolver::class);
        $source = $resolver->resolve($document, $canonicalPdf, $contract::SOURCE_SHA256);
        $this->assertNotSame($source->canonicalPath, $source->snapshotPath);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($source->snapshotPath)), -4));

        try {
            $dataset = $this->app->make(FullManualDatasetPreparer::class)->prepare($source, $contract);
            $this->assertSame($contract::DATASET_DIGEST, $dataset->datasetDigest);
            $this->assertSame($contract->reviewedInventory(), $dataset->reviewedInventory);

            $service = $this->service(new FullManualFailureInjector);
            $officialBefore = $this->officialState();
            $preview = $service->preview($document, $source, $dataset, $contract, str_repeat('a', 40));
            $this->assertSame('FIRST_APPLY', $preview['scenario']);
            $this->assertSame([625, 0, 0, 3], [$preview['created'], $preview['unchanged'], $preview['updated'], $preview['rejected']]);
            $this->assertFalse($preview['mutation_performed']);
            $this->assertSame($officialBefore, $this->officialState());

            foreach (['early', 'middle', 'final_child', 'reconciliation', 'run_completion'] as $checkpoint) {
                try {
                    $this->service(new class($checkpoint) extends FullManualFailureInjector
                    {
                        public function __construct(private readonly string $target) {}

                        public function checkpoint(string $checkpoint): void
                        {
                            if ($checkpoint === $this->target) {
                                throw new RuntimeException("Injected {$checkpoint} failure.");
                            }
                        }
                    })->apply($document, $source, $dataset, $contract, str_repeat('a', 40));
                    $this->fail("The {$checkpoint} failure was not injected.");
                } catch (RuntimeException $exception) {
                    $this->assertSame("Injected {$checkpoint} failure.", $exception->getMessage());
                }
                $this->assertSame($officialBefore, $this->officialState(), "{$checkpoint} must roll back every official write");
            }

            $protectedBefore = $this->app->make(ProtectedMaintenanceState::class)->snapshot();
            $applied = $service->apply($document, $source, $dataset, $contract, str_repeat('a', 40));
            $this->assertTrue($applied['mutation_performed']);
            $this->assertSame('COMMITTED', $applied['transaction']);
            $this->assertTrue($applied['verification']['verified']);
            $this->assertSame($protectedBefore, $this->app->make(ProtectedMaintenanceState::class)->snapshot());
            $stable = $this->stableOfficialRows();

            $rerun = $service->apply($document->fresh(), $source, $dataset, $contract, str_repeat('a', 40));
            $this->assertFalse($rerun['mutation_performed']);
            $this->assertSame('NO_OP', $rerun['transaction']);
            $this->assertSame([0, 625, 0, 3], [$rerun['created'], $rerun['unchanged'], $rerun['updated'], $rerun['rejected']]);
            $this->assertSame($stable, $this->stableOfficialRows());

            $entry = MaintenanceOfficialErrorEntry::with('ingestionRun')->where('code', 'C-3913')->firstOrFail();
            $api = (new MaintenanceOfficialErrorEntryResource($entry))->toArray(Request::create('/'));
            $this->assertSame($contract::SOURCE_SHA256, $api['provenance']['ingestion_run']['source_pdf_sha256']);
            $this->assertSame($contract::DATASET_DIGEST, $api['provenance']['ingestion_run']['dataset_digest']);
            $this->assertArrayNotHasKey('source_storage_path', $api['provenance']['ingestion_run']);

            DB::table('maintenance_official_error_entries')->where('id', $entry->id)->update(['normalized_digest' => str_repeat('0', 64)]);
            try {
                $service->preview($document->fresh(), $source, $dataset, $contract, str_repeat('a', 40));
                $this->fail('Unexpected UPDATE state was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('UPDATED action', $exception->getMessage());
            }
            DB::table('maintenance_official_error_entries')->where('id', $entry->id)->update(['normalized_digest' => $dataset->reviewedInventory[array_search('C-3913', array_column($dataset->reviewedInventory, 'code'), true)]['entry_digest']]);

            $step = DB::table('maintenance_official_error_steps')->where('error_entry_id', $entry->id)->orderBy('step_number')->first();
            $this->assertNotNull($step);
            DB::table('maintenance_official_error_steps')->where('id', $step->id)->update(['step_number' => 99]);
            try {
                $service->verify($document->fresh(), $dataset, $contract);
                $this->fail('Step-sequence corruption was not detected.');
            } catch (RuntimeException $exception) {
                $this->assertMatchesRegularExpression('/step|ordered children|sequence/i', $exception->getMessage());
            }
            DB::table('maintenance_official_error_steps')->where('id', $step->id)->update(['step_number' => $step->step_number]);
            $this->assertTrue($service->verify($document->fresh(), $dataset, $contract)['verified']);
        } finally {
            $source->cleanup();
        }

        $this->assertSame($beforePdf, $this->fileIdentity($canonicalPdf));
    }

    public function test_cleanup_refuses_to_unlink_authoritative_path_and_resolver_refuses_alias_mismatch(): void
    {
        $dir = sys_get_temp_dir().'/v21-resolver-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $sourcePath = $dir.'/authoritative.pdf';
        file_put_contents($sourcePath, 'authoritative fixture bytes');
        $this->beforeApplicationDestroyed(function () use ($sourcePath, $dir): void {
            @unlink($sourcePath);
            @rmdir($dir);
        });
        config(['filesystems.disks.v21_resolver' => ['driver' => 'local', 'root' => $dir, 'throw' => true]]);
        $document = MaintenanceDocument::create([
            'title' => 'Resolver fixture', 'document_type' => 'SERVICE_MANUAL',
            'file_reference' => 'authoritative.pdf', 'file_path' => 'authoritative.pdf',
            'file_name' => 'authoritative.pdf', 'file_size' => filesize($sourcePath),
            'mime_type' => 'application/pdf', 'storage_disk' => 'v21_resolver',
            'status' => 'PUBLISHED', 'is_active' => true,
        ]);
        $resolved = new ResolvedOfficialDocumentSource(
            $sourcePath, $sourcePath, hash_file('sha256', $sourcePath), basename($sourcePath), 'v21_resolver', 'authoritative.pdf'
        );
        $resolved->cleanup();
        $this->assertFileExists($sourcePath);

        $wrong = $dir.'/wrong.pdf';
        file_put_contents($wrong, 'wrong');
        try {
            $this->app->make(OfficialDocumentSourceResolver::class)->resolve($document, $wrong, hash_file('sha256', $sourcePath));
            $this->fail('A mismatched operator path was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('--pdf must resolve exactly', $exception->getMessage());
        } finally {
            @unlink($wrong);
        }
        try {
            $this->app->make(OfficialDocumentSourceResolver::class)->resolve($document, $sourcePath, str_repeat('0', 64));
            $this->fail('A mismatched source hash was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('SHA-256 does not match', $exception->getMessage());
        }
        $this->assertSame('authoritative fixture bytes', file_get_contents($sourcePath));
    }

    public function test_apply_requires_every_exact_interlock_before_source_resolution(): void
    {
        $document = MaintenanceDocument::create([
            'title' => 'Guard fixture', 'document_type' => 'SERVICE_MANUAL',
            'file_reference' => 'missing.pdf', 'file_path' => 'missing.pdf',
            'file_name' => 'missing.pdf', 'mime_type' => 'application/pdf', 'storage_disk' => 'local',
            'status' => 'PUBLISHED', 'is_active' => true,
        ]);
        $contract = new ReviewedFullManualContract;
        $options = [
            '--document' => $document->id, '--pdf' => '/definitely/not/read.pdf',
            '--contract' => $contract::NAME, '--expected-sha256' => $contract::SOURCE_SHA256,
            '--expected-dataset-digest' => str_repeat('0', 64),
            '--expected-discovered' => '628', '--expected-eligible' => '625',
            '--expected-environment' => 'testing', '--apply' => true,
        ];

        $this->artisan('maintenance:v2-ingest-full-manual', $options)
            ->assertFailed()
            ->expectsOutputToContain('--expected-dataset-digest must exactly match');
        $this->assertDatabaseCount('maintenance_official_ingestion_runs', 0);
        $this->assertDatabaseCount('maintenance_official_error_entries', 0);
    }

    public function test_simulated_production_apply_requires_contract_specific_confirmation_before_source_resolution(): void
    {
        $document = MaintenanceDocument::create([
            'title' => 'Production guard fixture', 'document_type' => 'SERVICE_MANUAL',
            'file_reference' => 'missing.pdf', 'file_path' => 'missing.pdf',
            'file_name' => 'missing.pdf', 'mime_type' => 'application/pdf', 'storage_disk' => 'local',
            'status' => 'PUBLISHED', 'is_active' => true,
        ]);
        $contract = new ReviewedFullManualContract;
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->artisan('maintenance:v2-ingest-full-manual', [
            '--document' => $document->id, '--pdf' => '/definitely/not/read.pdf',
            '--contract' => $contract::NAME, '--expected-sha256' => $contract::SOURCE_SHA256,
            '--expected-dataset-digest' => $contract::DATASET_DIGEST,
            '--expected-discovered' => '628', '--expected-eligible' => '625',
            '--expected-environment' => 'production', '--apply' => true,
        ])->assertFailed()->expectsOutputToContain('Production apply requires the exact contract-specific --confirm token');
        $this->assertDatabaseCount('maintenance_official_ingestion_runs', 0);
    }

    private function service(FullManualFailureInjector $injector): FullManualIngestionService
    {
        return new FullManualIngestionService(
            $this->app->make(OfficialKnowledgeIngestionService::class),
            $this->app->make(OfficialDocumentSourceResolver::class),
            $this->app->make(FullManualIntegrityVerifier::class),
            $this->app->make(ProtectedMaintenanceState::class),
            $injector,
        );
    }

    /** @return array<string, int> */
    private function officialState(): array
    {
        return collect([
            'maintenance_official_ingestion_runs', 'maintenance_official_error_entries',
            'maintenance_official_error_applicabilities', 'maintenance_official_error_parts',
            'maintenance_official_error_steps', 'maintenance_official_error_references',
        ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }

    /** @return array<string, string> */
    private function stableOfficialRows(): array
    {
        return collect(array_keys($this->officialState()))->mapWithKeys(function (string $table): array {
            $context = hash_init('sha256');
            foreach (DB::table($table)->orderBy('id')->get() as $row) {
                $values = (array) $row;
                ksort($values, SORT_STRING);
                $json = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                hash_update($context, pack('N', strlen($json)).$json);
            }

            return [$table => hash_final($context)];
        })->all();
    }

    /** @return array{realpath: string, size: int, inode: int, mtime: int, sha256: string} */
    private function fileIdentity(string $path): array
    {
        return [
            'realpath' => (string) realpath($path), 'size' => filesize($path), 'inode' => fileinode($path),
            'mtime' => filemtime($path), 'sha256' => (string) hash_file('sha256', $path),
        ];
    }
}
