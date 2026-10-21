<?php

namespace Tests\Feature;

use App\Models\MaintenanceDocument;
use App\Models\MaintenanceOfficialErrorEntry;
use App\Models\User;
use App\Services\OfficialKnowledgeIngestion\Jam\JamCodeContract;
use App\Services\OfficialKnowledgeIngestion\Jam\JamCodeDatasetPreparer;
use App\Services\OfficialKnowledgeIngestion\Jam\JamCodeIngestionService;
use App\Services\OfficialKnowledgeIngestion\Jam\JamCodeTableParser;
use App\Services\OfficialKnowledgeIngestion\Jam\OfficialPdfSegmentAcquirer;
use App\Services\OfficialKnowledgeIngestion\OfficialDocumentSourceResolver;
use App\Services\OfficialKnowledgeIngestion\OfficialKnowledgeCanonicalizer;
use App\Services\OfficialKnowledgeIngestion\OfficialKnowledgeIngestionService;
use App\Services\OfficialKnowledgeIngestion\ProtectedMaintenanceState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/**
 * Official J-code (jam indication) table: geometry-based extraction and the additive import.
 * All text below is synthetic; only the page geometry mirrors the real manual.
 */
class JamCodeIngestionTest extends TestCase
{
    use RefreshDatabase;

    // x anchors of the real table: class < 50, code 54.0, cause 105.0, result 283.6, correction 385.6
    private function header(): array
    {
        return [[4.2, 735.7, 'Classificatio'], [57.9, 730.9, 'JAM Code'], [179.8, 730.9, 'Cause'], [297.2, 730.9, 'Resulting operation'], [428.0, 730.9, 'Correction'], [23.3, 726.1, 'n'], [0.0, 809.4, 'running header']];
    }

    /** @return array<int, list<array{0: float, 1: float, 2: string}>> */
    private function pages(): array
    {
        return [
            1310 => [...$this->header(),
                // 1. class + code + cause in one run; result and correction are their own runs; multi-line cause and correction
                [2.9, 713.5, 'Alpha           J-1001           During operation: first cause line'], [283.6, 713.5, 'If a jam occurs'], [385.6, 713.5, 'Remove the first'],
                [105.0, 703.9, 'second cause line, wrapped pre-'], [283.6, 703.9, 'the machine stops'], [385.6, 703.9, 'sheet.'],
                [105.0, 694.3, 'registration done.'],
                // 2. no result cell, same class (inherited)
                [54.0, 672.0, 'J-1002           When idling: sensor is active.'], [385.6, 672.0, 'Open the door and'], [385.6, 662.4, 'clear the path.'],
                // 3. result "-" merged with the correction in one run
                [54.0, 640.0, 'J-1003           When idling: other sensor.'], [283.6, 640.0, '-                                            Pull the tray out.'],
                // 4. new class whose label wraps onto the next line
                [2.9, 610.0, 'Beta/'], [54.0, 610.0, 'J-1101           Cause of beta.'], [283.6, 610.0, 'Stops immediately.               Check the Beta unit.'],
                [2.9, 600.4, '707'], [105.0, 600.4, 'more beta cause.'],
                [245.1, 27.3, 'K-25'],
            ],
            1311 => [...$this->header(),
                // continuation of the previous page's last row, then a repeated code under another option
                [105.0, 713.5, 'and a third beta line.'], [385.6, 713.5, 'Then restart.'],
                [2.9, 690.0, 'Gamma           J-1001           Same code, other option.'], [385.6, 690.0, 'Different procedure.'],
            ],
        ];
    }

    private function parse(?array $pages = null)
    {
        return (new JamCodeTableParser)->parse($pages ?? $this->pages());
    }

    public function test_cells_are_assigned_by_geometry_with_multiline_cells_and_empty_results(): void
    {
        $result = $this->parse();
        $this->assertSame([], $result->quarantined);
        $this->assertSame(5, $result->discovered);
        $rows = collect($result->rows)->keyBy(fn ($row) => $row->code.'/'.$row->classification);

        $first = $rows['J-1001/Alpha'];
        $this->assertSame('During operation: first cause line second cause line, wrapped pre-registration done.', $first->cause, 'hyphenated wrap is rejoined');
        $this->assertSame('If a jam occurs the machine stops', $first->resultingOperation);
        $this->assertSame('Remove the first sheet.', $first->correction);
        $this->assertSame([1310, 1310], [$first->pageStart, $first->pageEnd]);

        $this->assertNull($rows['J-1002/Alpha']->resultingOperation, 'empty result cell stays empty and the class is inherited');
        $this->assertSame('Open the door and clear the path.', $rows['J-1002/Alpha']->correction);
        $this->assertNull($rows['J-1003/Alpha']->resultingOperation, '"-" means no resulting operation');
        $this->assertSame('Pull the tray out.', $rows['J-1003/Alpha']->correction, 'merged result+correction run is split at the cell gap');

        $beta = $rows['J-1101/Beta/707'];
        $this->assertSame('Stops immediately.', $beta->resultingOperation);
        $this->assertSame('Check the Beta unit. Then restart.', $beta->correction);
        $this->assertSame('Cause of beta. more beta cause. and a third beta line.', $beta->cause, 'text continuing on the next page stays with its row');
        $this->assertSame([1310, 1311], [$beta->pageStart, $beta->pageEnd]);
    }

    public function test_repeated_codes_for_different_options_stay_distinct_variants(): void
    {
        $dataset = $this->preparer()->fromSegments($this->pages(), str_repeat('a', 64));
        $keys = array_map(fn ($e) => $e->code.'|'.$e->variantKey, $dataset['entries']);
        $this->assertContains('J-1001|ALPHA', $keys);
        $this->assertContains('J-1001|GAMMA', $keys);
        $this->assertSame(5, $dataset['metrics']['accepted']);
        $this->assertSame(4, $dataset['metrics']['distinct_codes']);
        $alpha = collect($dataset['entries'])->first(fn ($e) => $e->variantKey === 'ALPHA' && $e->code === 'J-1001');
        $gamma = collect($dataset['entries'])->first(fn ($e) => $e->variantKey === 'GAMMA');
        $this->assertNotSame($alpha->correction, $gamma->correction, 'option-specific procedures are never merged');
        $this->assertSame(['Alpha'], $alpha->applicabilities);
        $this->assertSame('J-1001', $gamma->code, 'the J identity is preserved, never rewritten as a C code');
    }

    public function test_ambiguous_rows_are_quarantined_not_guessed(): void
    {
        $pages = [1310 => [...$this->header(),
            [2.9, 713.5, 'Alpha           J-2001           Clean row.'], [385.6, 713.5, 'Fix it.'],
            // result/correction merged into the code-line run: which column is it? unknowable -> quarantine
            [54.0, 690.0, 'J-2002           Some cause.           Remove any jammed paper from'], [385.6, 680.4, 'the unit.'],
            [54.0, 660.0, 'J-2003           Another clean row.'], [385.6, 660.0, 'Fix three.'],
            // text outside every known column
            [54.0, 640.0, 'J-2004           Row with stray text.'], [200.0, 640.0, 'stray'], [385.6, 640.0, 'Fix four.'],
            // required cell missing
            [54.0, 620.0, 'J-2005           No correction at all.'],
        ]];
        $result = $this->parse($pages);
        $this->assertSame(['J-2001', 'J-2003'], array_map(fn ($r) => $r->code, $result->rows));
        $reasons = collect($result->quarantined)->pluck('reason', 'code')->all();
        $this->assertSame(['J-2002' => 'ambiguous_merged_cells', 'J-2004' => 'unrecognized_column', 'J-2005' => 'missing_required_cell'], $reasons);
        $this->assertSame(5, $result->discovered);
    }

    public function test_duplicate_identity_and_unknown_layout_fail_closed(): void
    {
        $pages = [1310 => [...$this->header(),
            [2.9, 713.5, 'Alpha           J-3001           First.'], [385.6, 713.5, 'Fix.'],
            [2.9, 690.0, 'Alpha           J-3001           Conflicting second.'], [385.6, 690.0, 'Other fix.'],
        ]];
        $result = $this->parse($pages);
        $this->assertCount(1, $result->rows);
        $this->assertSame('First.', $result->rows[0]->cause, 'the first identity is kept and the conflicting one is quarantined');
        $this->assertSame('duplicate_identity', $result->quarantined[0]['reason']);

        $this->expectException(RuntimeException::class);
        $this->parse([1310 => [[54.0, 700.0, 'no header here']]]);
    }

    private function preparer(): JamCodeDatasetPreparer
    {
        return new JamCodeDatasetPreparer(new class implements OfficialPdfSegmentAcquirer
        {
            public function acquire(string $pdfPath, array $pageNumbers): array
            {
                return [];
            }
        }, new JamCodeTableParser, new OfficialKnowledgeCanonicalizer);
    }

    // ------------------------------------------------------------------ additive import

    private function documentWithFile(): array
    {
        $dir = storage_path('framework/testing/jam-'.uniqid());
        File::ensureDirectoryExists($dir);
        file_put_contents($dir.'/manual.pdf', 'synthetic-bytes-'.uniqid());
        config(['filesystems.disks.jam_test' => ['driver' => 'local', 'root' => $dir, 'throw' => true], 'release.git_sha' => str_repeat('b', 40)]);
        $document = MaintenanceDocument::create([
            'title' => 'Synthetic manual', 'document_type' => 'SERVICE_MANUAL', 'file_reference' => 'manual.pdf', 'file_path' => 'manual.pdf',
            'file_name' => 'manual.pdf', 'file_size' => filesize($dir.'/manual.pdf'), 'mime_type' => 'application/pdf',
            'storage_disk' => 'jam_test', 'status' => 'PUBLISHED', 'is_active' => true,
        ]);

        return [$document, realpath($dir.'/manual.pdf'), $dir];
    }

    private function service(): JamCodeIngestionService
    {
        return new JamCodeIngestionService($this->app->make(OfficialKnowledgeIngestionService::class), $this->app->make(OfficialDocumentSourceResolver::class), $this->app->make(ProtectedMaintenanceState::class));
    }

    private function cEntry(MaintenanceDocument $document, string $code = 'C-0213'): MaintenanceOfficialErrorEntry
    {
        return MaintenanceOfficialErrorEntry::create(['document_id' => $document->id, 'code' => $code, 'variant_key' => 'MAIN_BODY', 'classification' => 'Existing C procedure', 'raw_source_text' => 'existing '.$code, 'normalized_digest' => str_repeat('c', 64)]);
    }

    public function test_additive_import_preserves_c_codes_is_idempotent_and_refuses_changes(): void
    {
        [$document, $pdf, $dir] = $this->documentWithFile();
        try {
            $sha = hash_file('sha256', $pdf);
            $c = $this->cEntry($document);
            $this->cEntry($document, 'C-3913');
            $dataset = $this->preparer()->fromSegments($this->pages(), $sha);
            $contract = new JamCodeContract(JamCodeContract::NAME, $sha, 1310, 1311, $dataset['digest'], $dataset['metrics'], $dataset['inventory'], $dataset['quarantine']);
            $source = $this->app->make(OfficialDocumentSourceResolver::class)->resolve($document, $pdf, $sha);
            $service = $this->service();
            $cBefore = DB::table('maintenance_official_error_entries')->where('code', 'like', 'C-%')->orderBy('id')->get()->toJson();

            $preview = $service->preview($document, $dataset, $contract, $sha);
            $this->assertSame('FIRST_APPLY', $preview['scenario']);
            $this->assertSame(5, $preview['created']);
            $this->assertSame(2, $preview['c_code_parents']);
            $this->assertSame(0, MaintenanceOfficialErrorEntry::where('code', 'like', 'J-%')->count(), 'preview writes nothing');

            $applied = $service->apply($document, $source, $dataset, $contract, str_repeat('b', 40));
            $this->assertSame('COMMITTED', $applied['transaction']);
            $this->assertSame('PASS', $applied['verification']['status']);
            $this->assertSame(5, MaintenanceOfficialErrorEntry::where('code', 'like', 'J-%')->count());
            $this->assertSame($cBefore, DB::table('maintenance_official_error_entries')->where('code', 'like', 'C-%')->orderBy('id')->get()->toJson(), 'C parents are byte-for-byte untouched');
            $this->assertNull($c->fresh()->ingestion_run_id);

            $jam = MaintenanceOfficialErrorEntry::with(['steps', 'applicabilities'])->where('code', 'J-1001')->where('variant_key', 'GAMMA')->firstOrFail();
            $this->assertSame('Jam indication: Gamma', $jam->classification);
            $this->assertSame('Different procedure.', $jam->correction);
            $this->assertSame(['Different procedure.'], $jam->steps->pluck('instruction')->all());
            $this->assertSame('1.1', $jam->section_number);
            $this->assertSame([1311, 1311], [$jam->source_page_start, $jam->source_page_end]);

            $stable = DB::table('maintenance_official_error_entries')->orderBy('id')->get()->toJson();
            $rerun = $service->apply($document->fresh(), $source, $dataset, $contract, str_repeat('b', 40));
            $this->assertSame('NO_OP', $rerun['transaction']);
            $this->assertSame($stable, DB::table('maintenance_official_error_entries')->orderBy('id')->get()->toJson(), 'a rerun creates no duplicates');
            $this->assertSame(1, DB::table('maintenance_official_ingestion_runs')->count());
            $this->assertSame('PASS', $service->verify($document->fresh(), $dataset, $contract, $sha)['status']);

            // Any drift in a stored J entry makes the next run refuse instead of overwriting.
            DB::table('maintenance_official_error_entries')->where('id', $jam->id)->update(['normalized_digest' => str_repeat('0', 64)]);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('additive import is refused');
            $service->preview($document->fresh(), $dataset, $contract, $sha);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_contract_mismatches_are_refused_before_any_write(): void
    {
        [$document, $pdf, $dir] = $this->documentWithFile();
        try {
            $sha = hash_file('sha256', $pdf);
            $dataset = $this->preparer()->fromSegments($this->pages(), $sha);
            $good = new JamCodeContract(JamCodeContract::NAME, $sha, 1310, 1311, $dataset['digest'], $dataset['metrics'], $dataset['inventory'], $dataset['quarantine']);
            foreach ([
                'digest' => new JamCodeContract(JamCodeContract::NAME, $sha, 1310, 1311, str_repeat('0', 64), $dataset['metrics'], $dataset['inventory'], $dataset['quarantine']),
                'count' => new JamCodeContract(JamCodeContract::NAME, $sha, 1310, 1311, $dataset['digest'], ['accepted' => 999] + $dataset['metrics'], $dataset['inventory'], $dataset['quarantine']),
                'inventory' => new JamCodeContract(JamCodeContract::NAME, $sha, 1310, 1311, $dataset['digest'], $dataset['metrics'], array_slice($dataset['inventory'], 1), $dataset['quarantine']),
                'quarantine' => new JamCodeContract(JamCodeContract::NAME, $sha, 1310, 1311, $dataset['digest'], $dataset['metrics'], $dataset['inventory'], [['code' => 'J-9', 'page' => 1, 'reason' => 'x']]),
            ] as $label => $contract) {
                try {
                    $this->service()->preview($document, $dataset, $contract, $sha);
                    $this->fail("{$label} mismatch was accepted");
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('jam-code', $exception->getMessage());
                }
            }
            try {
                $this->service()->preview($document, $dataset, $good, str_repeat('f', 64));
                $this->fail('wrong source accepted');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('source PDF', $exception->getMessage());
            }
            $this->assertSame(0, DB::table('maintenance_official_ingestion_runs')->count());
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_official_search_returns_j_codes_and_keeps_c_and_j_distinct(): void
    {
        [$document, $pdf, $dir] = $this->documentWithFile();
        try {
            $sha = hash_file('sha256', $pdf);
            $dataset = $this->preparer()->fromSegments($this->pages(), $sha);
            $contract = new JamCodeContract(JamCodeContract::NAME, $sha, 1310, 1311, $dataset['digest'], $dataset['metrics'], $dataset['inventory'], $dataset['quarantine']);
            $source = $this->app->make(OfficialDocumentSourceResolver::class)->resolve($document, $pdf, $sha);
            $this->cEntry($document, 'C-1101');
            $this->service()->apply($document, $source, $dataset, $contract, str_repeat('b', 40));

            $user = User::factory()->create(['status' => 'active']);
            $codes = fn (string $q) => collect($this->actingAs($user)->getJson('/api/v1/maintenance/official-error-entries?search='.urlencode($q))->assertOk()->json('data'))->pluck('code')->unique()->values()->all();
            foreach (['J-1101', 'J1101', 'j1101'] as $q) {
                $this->assertSame(['J-1101'], $codes($q), $q);
            }
            $this->assertSame(['C-1101'], $codes('C-1101'), 'the C code with the same digits is a different identity');
            $this->assertSame(['J-1001'], $codes('J-1001'));
            $both = $this->actingAs($user)->getJson('/api/v1/maintenance/official-error-entries?search=J-1001')->json('data');
            $this->assertCount(2, $both, 'both option-specific J-1001 procedures are returned, never collapsed');
            $this->assertEqualsCanonicalizing(['Jam indication: Alpha', 'Jam indication: Gamma'], array_column($both, 'classification'));

            // Bare digits are ambiguous between the families: both are returned, each keeping its own identity.
            $this->assertSame(['C-1101', 'J-1101'], $codes('1101'));
            $this->assertSame(['C-1101', 'J-1101'], $codes(' 11-01 '));
            $this->assertSame(['C-1101'], $codes('C1101'));
            $this->assertSame(['J-1101'], $codes('J1101'));
            $this->assertSame(['J-1001'], $codes('1001'), 'only one family has this number');
            $variants = $this->actingAs($user)->getJson('/api/v1/maintenance/official-error-entries?search=1001')->json('data');
            $this->assertEqualsCanonicalizing(['ALPHA', 'GAMMA'], array_column($variants, 'variant_key'), 'option variants stay separate');
            $this->assertSame([], $codes('C1001'), 'an explicit C prefix never falls through to the J family');
            $this->assertSame([], $codes('9999'));
            $byCode = collect($this->actingAs($user)->getJson('/api/v1/maintenance/official-error-entries?code=1101')->assertOk()->json('data'))->pluck('code')->all();
            $this->assertSame(['C-1101', 'J-1101'], $byCode);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_real_manual_reproduces_the_reviewed_dataset(): void
    {
        $pdf = (string) getenv('MAINTENANCE_V21_PDF');
        $python = (string) getenv('MAINTENANCE_OFFICIAL_PYTHON_EXECUTABLE');
        if ($pdf === '' || ! is_file($pdf) || $python === '') {
            $this->markTestSkipped('Set MAINTENANCE_V21_PDF and MAINTENANCE_OFFICIAL_PYTHON_EXECUTABLE for the real-manual rehearsal.');
        }
        $minor = (int) trim((string) shell_exec(escapeshellarg($python).' -c "import sys;print(sys.version_info.minor)"'));
        config(['maintenance.official_knowledge.python_executable' => $python, 'maintenance.official_knowledge.python_minor' => $minor]);

        $contract = JamCodeContract::reviewed();
        $this->assertSame($contract->sourceSha256, hash_file('sha256', $pdf));
        $pages = $this->app->make(OfficialPdfSegmentAcquirer::class)->acquire($pdf, $contract->pages());
        $dataset = $this->app->make(JamCodeDatasetPreparer::class)->fromSegments($pages, $contract->sourceSha256);

        $this->assertSame($contract->datasetDigest, $dataset['digest']);
        $this->assertSame($contract->inventory, $dataset['inventory']);
        $this->assertSame($contract->quarantine, $dataset['quarantine']);
        $this->assertSame([378, 376, 2, 371], [$dataset['metrics']['discovered'], $dataset['metrics']['accepted'], $dataset['metrics']['quarantined'], $dataset['metrics']['distinct_codes']]);
        $entries = collect($dataset['entries'])->keyBy(fn ($e) => $e->code.'|'.$e->variantKey);

        // Spot checks against the printed manual: first and last page, multi-line cells, repeated codes by option.
        $this->assertSame('Take out paper from the bypass tray once, and remove any jammed paper.', $entries['J-1001|BYPASS']->correction);
        $this->assertSame('Pull out the ADU and remove any jammed paper.', $entries['J-9451|ADU']->correction);
        $this->assertSame('Remove any jammed paper from the FS and the main body.', $entries['J-7225|FS']->correction);
        $this->assertSame('Remove any jammed paper from the SD and the main body.', $entries['J-7225|SD-510']->correction);
        $this->assertStringContainsString('(FS-612) The folding paper exit sensor (PS25)', $entries['J-7225|FS']->cause);
        $this->assertNull($entries['J-7225|FS']->note, 'the printed table has no resulting operation for this option');
        $this->assertSame('Resulting operation: The SD and the main body stop immediately.', $entries['J-7225|SD-510']->note);
        $this->assertSame([1310, 1310], [$entries['J-1001|BYPASS']->sourcePageStart, $entries['J-1001|BYPASS']->sourcePageEnd]);
        $this->assertArrayNotHasKey('J-7272|SD-510', $entries->all(), 'ambiguous rows stay quarantined');
        $this->assertArrayNotHasKey('J-7524|FD', $entries->all());

        // Full command rehearsal on the real file: preview, apply, identical rerun, verify - C parents untouched.
        $canonical = realpath($pdf);
        config(['filesystems.disks.jam_real' => ['driver' => 'local', 'root' => dirname($canonical), 'throw' => true], 'release.git_sha' => str_repeat('c', 40)]);
        $document = MaintenanceDocument::create([
            'title' => 'Disposable jam rehearsal', 'document_type' => 'SERVICE_MANUAL', 'file_reference' => basename($canonical), 'file_path' => basename($canonical),
            'file_name' => basename($canonical), 'file_size' => filesize($canonical), 'mime_type' => 'application/pdf', 'storage_disk' => 'jam_real', 'status' => 'PUBLISHED', 'is_active' => true,
        ]);
        $this->cEntry($document, 'C-0213');
        $this->cEntry($document, 'C-3913');
        $cBefore = DB::table('maintenance_official_error_entries')->orderBy('id')->get()->toJson();
        $args = ['--document' => $document->id, '--pdf' => $canonical];

        $this->artisan('maintenance:v2-ingest-jam-codes', $args + ['--preview' => true])->assertSuccessful()->expectsOutputToContain('"scenario": "FIRST_APPLY"');
        $this->assertSame(0, MaintenanceOfficialErrorEntry::where('code', 'like', 'J-%')->count());
        $this->artisan('maintenance:v2-ingest-jam-codes', $args + ['--apply' => true])->assertFailed()->expectsOutputToContain('--expected-dataset-digest must exactly match');
        $apply = $args + ['--apply' => true, '--expected-dataset-digest' => $contract->datasetDigest, '--expected-environment' => app()->environment()];
        $this->artisan('maintenance:v2-ingest-jam-codes', $apply)->assertSuccessful()->expectsOutputToContain('JAM_CODE_APPLY=PASS');
        $this->assertSame(376, MaintenanceOfficialErrorEntry::where('code', 'like', 'J-%')->count());
        $this->assertSame(371, MaintenanceOfficialErrorEntry::where('code', 'like', 'J-%')->distinct()->count('code'));
        $this->assertSame($cBefore, DB::table('maintenance_official_error_entries')->where('code', 'like', 'C-%')->orderBy('id')->get()->toJson());
        $jBefore = DB::table('maintenance_official_error_entries')->where('code', 'like', 'J-%')->orderBy('id')->get()->toJson();
        $this->artisan('maintenance:v2-ingest-jam-codes', $apply)->assertSuccessful()->expectsOutputToContain('"transaction": "NO_OP"');
        $this->assertSame($jBefore, DB::table('maintenance_official_error_entries')->where('code', 'like', 'J-%')->orderBy('id')->get()->toJson());
        $this->artisan('maintenance:v2-ingest-jam-codes', $args + ['--verify' => true])->assertSuccessful()->expectsOutputToContain('JAM_CODE_VERIFY=PASS');
        $this->assertSame(1, DB::table('maintenance_official_ingestion_runs')->count());
    }
}
