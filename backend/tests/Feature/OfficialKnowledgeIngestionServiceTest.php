<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceOfficialErrorEntry;
use App\Models\User;
use App\Services\OfficialKnowledgeIngestion\OfficialKnowledgeIngestionAction;
use App\Services\OfficialKnowledgeIngestion\OfficialKnowledgeIngestionService;
use App\Services\OfficialKnowledgeParsing\ParsedOfficialErrorEntry;
use App\Services\OfficialKnowledgeParsing\ParsedOfficialReference;
use App\Services\OfficialKnowledgeParsing\ParserOutcome;
use App\Services\OfficialKnowledgeParsing\SemanticErrorCodeParser;
use App\Services\OfficialKnowledgeParsing\SourceTextChunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class OfficialKnowledgeIngestionServiceTest extends TestCase
{
    use RefreshDatabase;

    private OfficialKnowledgeIngestionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OfficialKnowledgeIngestionService;
    }

    public function test_pass_persists_parent_children_and_exact_provenance(): void
    {
        $document = $this->document();
        $parsed = $this->parsed($this->raw(), 1606);

        $result = $this->service->ingest($document, $parsed);

        $this->assertSame(OfficialKnowledgeIngestionAction::CREATED, $result->action);
        $entry = $result->entry->fresh(['applicabilities', 'parts', 'steps', 'references']);
        $this->assertSame($document->id, $entry->document_id);
        $this->assertSame($parsed->sourceHash, $entry->source_hash);
        $this->assertSame($parsed->rawSourceText, $entry->raw_source_text);
        $this->assertSame([1606, 1606], [$entry->source_page_start, $entry->source_page_end]);
        $this->assertSame(['Main body'], $entry->applicabilities->pluck('scope_label')->all());
        $this->assertSame(['Fixture part A', 'Fixture part B'], $entry->parts->pluck('part_name')->all());
        $this->assertSame([1, 2], $entry->steps->pluck('step_number')->all());
        $this->assertSame(['WIRING_DIAGRAM'], $entry->references->pluck('reference_type')->all());
    }

    public function test_warn_and_fail_are_rejected_by_default_without_writes(): void
    {
        $document = $this->document();
        $warn = $this->parsed("2.1.2 C-A002 (FIXTURE)\nCause: Warning fixture.\nSolution:\n1. Step.");
        $fail = $this->parsed("2.1.3 C-A003 (FIXTURE)\nClassification: Fixture.\nSolution:\n1. First.\n3. Third.");

        $this->assertSame(ParserOutcome::WARN, $warn->outcome);
        $this->assertSame(ParserOutcome::FAIL, $fail->outcome);
        $this->assertSame(OfficialKnowledgeIngestionAction::REJECTED, $this->service->ingest($document, $warn)->action);
        $this->assertSame(OfficialKnowledgeIngestionAction::REJECTED, $this->service->ingest($document, $fail)->action);
        $this->assertDatabaseCount('maintenance_official_error_entries', 0);
    }

    public function test_identical_rerun_is_unchanged_with_stable_parent_and_child_ids(): void
    {
        $document = $this->document();
        $parsed = $this->parsed($this->raw());
        $first = $this->service->ingest($document, $parsed);
        $before = $this->ids($first->entry);

        $second = $this->service->ingest($document, $parsed);

        $this->assertSame(OfficialKnowledgeIngestionAction::UNCHANGED, $second->action);
        $this->assertSame($first->entry->id, $second->entry->id);
        $this->assertSame($before, $this->ids($second->entry));
        $this->assertDatabaseCount('maintenance_official_error_entries', 1);
        $this->assertDatabaseCount('maintenance_official_error_applicabilities', 1);
        $this->assertDatabaseCount('maintenance_official_error_parts', 2);
        $this->assertDatabaseCount('maintenance_official_error_steps', 2);
        $this->assertDatabaseCount('maintenance_official_error_references', 1);
    }

    public function test_changed_source_updates_same_parent_and_replaces_stale_children(): void
    {
        $document = $this->document();
        $v1 = $this->parsed($this->raw(partB: 'Old part', secondStep: 'Old step.'));
        $created = $this->service->ingest($document, $v1);
        $oldChildIds = $this->ids($created->entry);
        $v2 = $this->parsed($this->raw(partB: 'New part', secondStep: 'New step.'));

        $updated = $this->service->ingest($document, $v2);

        $this->assertSame(OfficialKnowledgeIngestionAction::UPDATED, $updated->action);
        $this->assertSame($created->entry->id, $updated->entry->id);
        $this->assertSame($v2->sourceHash, $updated->entry->source_hash);
        $this->assertSame($v2->rawSourceText, $updated->entry->raw_source_text);
        $this->assertSame(['Fixture part A', 'New part'], $updated->entry->parts()->pluck('part_name')->all());
        $this->assertSame(['First step.', 'New step.'], $updated->entry->steps()->pluck('instruction')->all());
        $this->assertNotSame($oldChildIds['parts'], $this->ids($updated->entry)['parts']);
        $this->assertDatabaseMissing('maintenance_official_error_parts', ['part_name' => 'Old part']);
        $this->assertDatabaseMissing('maintenance_official_error_steps', ['instruction' => 'Old step.']);
    }

    public function test_child_failure_rolls_back_parent_and_all_children(): void
    {
        $document = $this->document();
        $valid = $this->parsed($this->raw());
        $invalid = $this->copy($valid, references: [new ParsedOfficialReference('INVALID_TYPE', 'invalid')]);

        try {
            $this->service->ingest($document, $invalid);
            $this->fail('Expected child persistence to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Unsupported official error reference type', $exception->getMessage());
        }

        foreach ($this->officialTables() as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_c1103_variants_and_letter_code_families_remain_separate(): void
    {
        $document = $this->document();
        $entries = [
            $this->parsed($this->raw('2.8.13', 'C-1103', '(FS-531/612)', 'FS: Fixture A')),
            $this->parsed($this->raw('2.8.14', 'C-1103', '(FS-532)', 'FS: Fixture B')),
            $this->parsed($this->raw('2.25.26', 'C-C152')),
            $this->parsed($this->raw('2.26.1', 'C-D010')),
            $this->parsed($this->raw('2.26.31', 'C-E012')),
        ];

        $result = $this->service->ingestBatch($document, $entries);

        $this->assertTrue($result->persisted);
        $this->assertSame(2, MaintenanceOfficialErrorEntry::where('document_id', $document->id)->where('code', 'C-1103')->count());
        $this->assertSame(['FS-531_FS-612', 'FS-532'], MaintenanceOfficialErrorEntry::where('code', 'C-1103')->orderBy('variant_key')->pluck('variant_key')->all());
        $this->assertSame(['C-C152', 'C-D010', 'C-E012'], MaintenanceOfficialErrorEntry::whereIn('code', ['C-C152', 'C-D010', 'C-E012'])->orderBy('code')->pluck('code')->all());
    }

    public function test_batch_with_eight_valid_and_one_invalid_entry_persists_nothing(): void
    {
        $document = $this->document();
        $entries = $this->eightEntries();
        $entries[] = $this->parsed("9.9.9 C-Z999 (FIXTURE)\nCause: Missing classification.\nSolution:\n1. Step.");

        $result = $this->service->ingestBatch($document, $entries);

        $this->assertFalse($result->persisted);
        $this->assertContains(OfficialKnowledgeIngestionAction::REJECTED, array_column($result->results, 'action'));
        foreach ($this->officialTables() as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_valid_eight_entry_batch_persists_atomically_without_legacy_mutation(): void
    {
        $document = $this->document();
        $legacyTables = [
            'machine_error_codes', 'maintenance_error_solutions', 'maintenance_tickets',
            'maintenance_knowledge_entries', 'maintenance_document_imports', 'maintenance_document_pages',
            'maintenance_document_extractions', 'maintenance_knowledge_review_sessions',
        ];
        $before = collect($legacyTables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();

        $result = $this->service->ingestBatch($document, $this->eightEntries());

        $this->assertTrue($result->persisted);
        $this->assertDatabaseCount('maintenance_official_error_entries', 8);
        $this->assertSame(2, MaintenanceOfficialErrorEntry::where('code', 'C-1103')->count());
        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "{$table} must remain untouched");
        }
    }

    public function test_existing_v2_api_returns_service_persisted_structure(): void
    {
        $account = Account::create(['code' => 'INGEST', 'name' => 'Ingestion Test', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $user = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active', 'accepted_at' => now()]);
        $document = $this->document(['account_id' => $account->id]);
        $parsed = $this->parsed($this->raw(), 1606);
        $entry = $this->service->ingest($document, $parsed)->entry;

        $this->actingAs($user)->getJson("/api/v1/maintenance/official-error-entries/{$entry->id}")
            ->assertOk()
            ->assertJsonPath('data.code', 'C-3913')
            ->assertJsonPath('data.variant_key', 'MAIN_BODY')
            ->assertJsonPath('data.provenance.section_number', '2.20.31')
            ->assertJsonPath('data.provenance.source_page_start', 1606)
            ->assertJsonPath('data.provenance.source_hash', $parsed->sourceHash)
            ->assertJsonPath('data.raw_source_text', $parsed->rawSourceText)
            ->assertJsonCount(2, 'data.parts')
            ->assertJsonCount(2, 'data.steps')
            ->assertJsonCount(1, 'data.references');
    }

    private function document(array $overrides = []): MaintenanceDocument
    {
        return MaintenanceDocument::create($overrides + [
            'title' => 'Controlled ingestion fixture manual',
            'document_type' => 'SERVICE_MANUAL',
            'file_reference' => 'controlled-fixture.pdf',
            'status' => 'PUBLISHED',
            'is_active' => true,
        ]);
    }

    private function raw(
        string $section = '2.20.31',
        string $code = 'C-3913',
        string $headingApplicability = '',
        string $classification = 'Main body: Fixture classification',
        string $partB = 'Fixture part B',
        string $secondStep = 'Second step. (Wiring diagram: Main body: 1-A)',
    ): string {
        $suffix = $headingApplicability === '' ? '' : ' '.$headingApplicability;

        return implode("\n", [
            "{$section} {$code}{$suffix}",
            'Code:',
            $code,
            'Classification:',
            '      '.$classification,
            'Cause:',
            'Fixture cause.',
            'Estimated abnormal parts:',
            '• Fixture part A',
            '• '.$partB,
            'Solution:',
            '1. First step.',
            '2. '.$secondStep,
        ]);
    }

    private function parsed(string $raw, int $page = 100): ParsedOfficialErrorEntry
    {
        $result = (new SemanticErrorCodeParser)->parseChunks([new SourceTextChunk($raw, $page, true)]);
        $this->assertCount(1, $result->entries);

        return $result->entries[0];
    }

    /** @return list<ParsedOfficialErrorEntry> */
    private function eightEntries(): array
    {
        return [
            $this->parsed($this->raw('2.20.31', 'C-3913')),
            $this->parsed($this->raw('2.8.13', 'C-1103', '(FS-531/612)', 'FS: Fixture A')),
            $this->parsed($this->raw('2.8.14', 'C-1103', '(FS-532)', 'FS: Fixture B')),
            $this->parsed($this->raw('2.20.29', 'C-3911')),
            $this->parsed($this->raw('2.11.32', 'C-1334', '', 'PB: Fixture')),
            $this->parsed($this->raw('2.25.26', 'C-C152')),
            $this->parsed($this->raw('2.26.1', 'C-D010')),
            $this->parsed($this->raw('2.26.31', 'C-E012')),
        ];
    }

    private function copy(ParsedOfficialErrorEntry $entry, ?array $references = null): ParsedOfficialErrorEntry
    {
        return new ParsedOfficialErrorEntry(
            $entry->code,
            $entry->variantKey,
            $entry->sectionNumber,
            $entry->classification,
            $entry->cause,
            $entry->alertMeasure,
            $entry->correction,
            $entry->warning,
            $entry->note,
            $entry->isolationDipsw,
            $entry->detachedControl,
            $entry->applicabilities,
            $entry->parts,
            $entry->steps,
            $references ?? $entry->references,
            $entry->sourcePageStart,
            $entry->sourcePageEnd,
            $entry->rawSourceText,
            $entry->sourceHash,
            $entry->diagnostics,
            $entry->outcome,
        );
    }

    /** @return array<string, list<string>> */
    private function ids(MaintenanceOfficialErrorEntry $entry): array
    {
        return [
            'applicabilities' => $entry->applicabilities()->pluck('id')->all(),
            'parts' => $entry->parts()->pluck('id')->all(),
            'steps' => $entry->steps()->pluck('id')->all(),
            'references' => $entry->references()->pluck('id')->all(),
        ];
    }

    /** @return list<string> */
    private function officialTables(): array
    {
        return [
            'maintenance_official_error_entries',
            'maintenance_official_error_applicabilities',
            'maintenance_official_error_parts',
            'maintenance_official_error_steps',
            'maintenance_official_error_references',
        ];
    }
}
