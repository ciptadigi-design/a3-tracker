<?php

namespace Tests\Feature;

use App\Models\MaintenanceDocument;
use App\Services\OfficialKnowledgeIngestion\OfficialKnowledgeManifest;
use App\Services\OfficialKnowledgeIngestion\OfficialKnowledgeManifestProvider;
use App\Services\OfficialKnowledgeIngestion\OfficialKnowledgeSelector;
use App\Services\OfficialKnowledgeIngestion\OfficialPdfTextAcquirer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngestOfficialKnowledgeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_create_and_performs_zero_official_mutation(): void
    {
        [$document, $pdf, $hash] = $this->commandFixture();
        $this->bindControlledSource($hash);

        $this->artisan('maintenance:v2-ingest-official', $this->commandOptions($document, $pdf, $hash, dryRun: true))
            ->assertSuccessful()
            ->expectsOutputToContain('C-3913 | 2.20.31 | MAIN_BODY | PASS | CREATED')
            ->expectsOutputToContain('OFFICIAL_INGESTION_MODE=DRY_RUN')
            ->expectsOutputToContain('OFFICIAL_INGESTION_SELECTED=1')
            ->expectsOutputToContain('OFFICIAL_INGESTION_TRANSACTION=NOT_STARTED');

        $this->assertDatabaseCount('maintenance_official_error_entries', 0);
    }

    public function test_apply_persists_prepared_manifest_and_identical_rerun_is_unchanged(): void
    {
        [$document, $pdf, $hash] = $this->commandFixture();
        $this->bindControlledSource($hash);
        $options = $this->commandOptions($document, $pdf, $hash, apply: true);

        $this->artisan('maintenance:v2-ingest-official', $options)
            ->assertSuccessful()
            ->expectsOutputToContain('PASS | CREATED')
            ->expectsOutputToContain('OFFICIAL_INGESTION_TRANSACTION=COMMITTED');
        $id = $document->officialErrorEntries()->sole()->id;

        $this->artisan('maintenance:v2-ingest-official', $options)
            ->assertSuccessful()
            ->expectsOutputToContain('PASS | UNCHANGED');

        $this->assertSame($id, $document->officialErrorEntries()->sole()->id);
        $this->assertDatabaseCount('maintenance_official_error_entries', 1);
    }

    public function test_production_environment_is_refused_before_any_source_or_database_work(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->artisan('maintenance:v2-ingest-official')
            ->assertFailed()
            ->expectsOutputToContain('APP_ENV=production is forbidden');

        $this->assertDatabaseCount('maintenance_official_error_entries', 0);
    }

    public function test_pdf_hash_mismatch_is_refused(): void
    {
        [$document, $pdf] = $this->commandFixture();
        $expectedHash = hash('sha256', 'different expected bytes');
        $this->bindControlledSource($expectedHash);

        $this->artisan('maintenance:v2-ingest-official', $this->commandOptions($document, $pdf, $expectedHash, dryRun: true))
            ->assertFailed()
            ->expectsOutputToContain('source PDF SHA-256 does not match');

        $this->assertDatabaseCount('maintenance_official_error_entries', 0);
    }

    public function test_missing_pdf_is_refused(): void
    {
        [$document, $pdf, $hash] = $this->commandFixture();
        $this->bindControlledSource($hash);
        $options = $this->commandOptions($document, $pdf, $hash, dryRun: true);
        $options['--pdf'] = sys_get_temp_dir().'/missing-official-source.pdf';

        $this->artisan('maintenance:v2-ingest-official', $options)
            ->assertFailed()
            ->expectsOutputToContain('--pdf path must be a readable file');

        $this->assertDatabaseCount('maintenance_official_error_entries', 0);
    }

    public function test_missing_document_and_unknown_manifest_are_refused(): void
    {
        [$document, $pdf, $hash] = $this->commandFixture();
        $this->bindControlledSource($hash);
        $missingOptions = $this->commandOptions($document, $pdf, $hash, dryRun: true);
        $missingOptions['--document'] = '00000000-0000-4000-8000-000000000001';

        $this->artisan('maintenance:v2-ingest-official', $missingOptions)
            ->assertFailed()
            ->expectsOutputToContain('--document record does not exist');

        $unknownOptions = $this->commandOptions($document, $pdf, $hash, dryRun: true);
        $unknownOptions['--manifest'] = 'unknown';
        $this->artisan('maintenance:v2-ingest-official', $unknownOptions)
            ->assertFailed()
            ->expectsOutputToContain('known non-empty controlled --manifest is required');
    }

    public function test_selector_identity_mismatch_is_refused_without_mutation(): void
    {
        [$document, $pdf, $hash] = $this->commandFixture();
        $this->bindControlledSource($hash, expectedCode: 'C-9999');

        $this->artisan('maintenance:v2-ingest-official', $this->commandOptions($document, $pdf, $hash, dryRun: true))
            ->assertFailed()
            ->expectsOutputToContain('Parsed identity differs from requested section 2.20.31');

        $this->assertDatabaseCount('maintenance_official_error_entries', 0);
    }

    /** @return array{MaintenanceDocument, string, string} */
    private function commandFixture(): array
    {
        $document = MaintenanceDocument::create([
            'title' => 'Command fixture manual',
            'file_reference' => 'command-fixture.pdf',
            'status' => 'PUBLISHED',
            'is_active' => true,
        ]);
        $pdf = tempnam(sys_get_temp_dir(), 'official-command-');
        $this->assertIsString($pdf);
        file_put_contents($pdf, 'fixture PDF bytes');
        $this->beforeApplicationDestroyed(fn () => @unlink($pdf));

        return [$document, $pdf, hash_file('sha256', $pdf)];
    }

    private function bindControlledSource(string $hash, string $expectedCode = 'C-3913'): void
    {
        $manifest = new OfficialKnowledgeManifest('test-one', $hash, [
            new OfficialKnowledgeSelector('2.20.31', $expectedCode, 'MAIN_BODY', [1]),
        ]);
        $this->app->instance(OfficialKnowledgeManifestProvider::class, new class($manifest) implements OfficialKnowledgeManifestProvider
        {
            public function __construct(private readonly OfficialKnowledgeManifest $manifest) {}

            public function find(string $name): ?OfficialKnowledgeManifest
            {
                return $name === $this->manifest->name ? $this->manifest : null;
            }
        });
        $this->app->instance(OfficialPdfTextAcquirer::class, new class implements OfficialPdfTextAcquirer
        {
            public function acquire(string $pdfPath, array $pageNumbers): array
            {
                return [1 => implode("\n", [
                    '2.20.31 C-3913*',
                    'Code:',
                    'C-3913*',
                    'Classification:',
                    '      Main body: Fixture classification',
                    'Cause:',
                    'Fixture cause.',
                    'Estimated abnormal parts:',
                    '• Fixture part',
                    'Solution:',
                    '1. Fixture step.',
                    '2.20.32 C-3917*',
                ])];
            }
        });
    }

    /** @return array<string, string|bool> */
    private function commandOptions(
        MaintenanceDocument $document,
        string $pdf,
        string $hash,
        bool $dryRun = false,
        bool $apply = false,
    ): array {
        return [
            '--document' => $document->id,
            '--pdf' => $pdf,
            '--sha256' => $hash,
            '--manifest' => 'test-one',
            '--dry-run' => $dryRun,
            '--apply' => $apply,
        ];
    }
}
