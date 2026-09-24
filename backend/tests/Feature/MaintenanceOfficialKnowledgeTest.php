<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\MachineErrorCode;
use App\Models\MachineModel;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceErrorSolution;
use App\Models\MaintenanceOfficialErrorEntry;
use App\Models\MaintenanceOfficialErrorReference;
use App\Models\Manufacturer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Fixtures\MaintenanceOfficialKnowledgeFixture;
use Tests\TestCase;

class MaintenanceOfficialKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $home = Account::create(['code' => 'HOME', 'name' => 'Home Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $foreign = Account::create(['code' => 'FRGN', 'name' => 'Foreign Account', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'C1070', 'name' => 'bizhub PRESS C1070']);
        $document = MaintenanceDocument::create([
            'account_id' => $home->id,
            'manufacturer_id' => $manufacturer->id,
            'machine_model_id' => $model->id,
            'title' => 'Fixture C1070 Service Manual',
            'document_type' => 'SERVICE_MANUAL',
            'file_reference' => 'fixture.pdf',
            'status' => 'PUBLISHED',
            'is_active' => true,
        ]);

        return compact('home', 'foreign', 'manufacturer', 'model', 'document');
    }

    private function member(Account $account): User
    {
        $user = User::factory()->create(['status' => 'active']);
        AccountMembership::create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
            'accepted_at' => now(),
        ]);

        return $user;
    }

    public function test_additive_migration_creates_only_the_official_knowledge_structure_and_expected_identity(): void
    {
        foreach ([
            'maintenance_official_error_entries',
            'maintenance_official_error_applicabilities',
            'maintenance_official_error_parts',
            'maintenance_official_error_steps',
            'maintenance_official_error_references',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} must exist");
        }
        $this->assertTrue(Schema::hasColumns('maintenance_official_error_entries', [
            'document_id', 'code', 'variant_key', 'section_number', 'classification', 'cause',
            'alert_measure', 'correction', 'warning', 'note', 'isolation_dipsw',
            'detached_control', 'source_page_start', 'source_page_end', 'raw_source_text', 'source_hash',
        ]));
        $identity = collect(Schema::getIndexes('maintenance_official_error_entries'))->firstWhere('name', 'moee_document_code_variant_uq');
        $this->assertNotNull($identity);
        $this->assertTrue($identity['unique']);
        $this->assertSame(['document_id', 'code', 'variant_key'], $identity['columns']);
        $this->assertFalse(Schema::hasColumn('machine_error_codes', 'variant_key'));
        $this->assertFalse(Schema::hasColumn('maintenance_error_solutions', 'error_entry_id'));
    }

    public function test_migration_rollback_drops_only_new_tables_and_can_be_reapplied(): void
    {
        $migration = require database_path('migrations/2026_09_23_000200_create_maintenance_official_error_knowledge.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('maintenance_official_error_entries'));
        $this->assertTrue(Schema::hasTable('machine_error_codes'));
        $this->assertTrue(Schema::hasTable('maintenance_error_solutions'));
        $this->assertTrue(Schema::hasTable('maintenance_tickets'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('maintenance_official_error_entries'));
    }

    public function test_c3913_preserves_provenance_raw_source_parts_and_exact_step_order(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $entry = MaintenanceOfficialKnowledgeFixture::c3913($f['document']);

        $this->assertSame([1, 2, 3, 4, 5], $entry->steps()->pluck('step_number')->all());
        $this->assertSame(['Fusing unit', 'Printer control board'], $entry->parts()->pluck('part_name')->all());
        $this->assertSame(hash('sha256', $entry->raw_source_text), $entry->source_hash);

        $list = $this->actingAs($user)->getJson('/api/v1/maintenance/official-error-entries?code=c-3913')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'C-3913')
            ->assertJsonPath('data.0.variant_key', 'MAIN_BODY')
            ->assertJsonPath('data.0.source.page_start', 101)
            ->json('data.0');
        $this->assertArrayNotHasKey('raw_source_text', $list);

        $detail = $this->actingAs($user)->getJson("/api/v1/maintenance/official-error-entries/{$entry->id}")
            ->assertOk()
            ->assertJsonPath('data.provenance.document.id', $f['document']->id)
            ->assertJsonPath('data.provenance.section_number', '2.20.31')
            ->assertJsonPath('data.provenance.source_page_end', 102)
            ->assertJsonPath('data.raw_source_text', $entry->raw_source_text)
            ->json('data');
        $this->assertSame([1, 2, 3, 4, 5], array_column($detail['steps'], 'step_number'));
        $this->assertSame('Replace the fusing unit.', $detail['steps'][4]['instruction']);
    }

    public function test_duplicate_c1103_variants_coexist_search_together_and_keep_children_isolated(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        [$a, $b] = MaintenanceOfficialKnowledgeFixture::c1103Variants($f['document']);

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame('FS-531_FS-612', $a->variant_key);
        $this->assertSame('FS-532', $b->variant_key);
        $this->assertSame(2, MaintenanceOfficialErrorEntry::where('document_id', $f['document']->id)->where('code', 'C-1103')->count());

        $rows = $this->actingAs($user)->getJson('/api/v1/maintenance/official-error-entries?code=C-1103')
            ->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertSame(['FS-531_FS-612', 'FS-532'], array_column($rows, 'variant_key'));

        $detailA = $this->actingAs($user)->getJson("/api/v1/maintenance/official-error-entries/{$a->id}")->assertOk()->json('data');
        $detailB = $this->actingAs($user)->getJson("/api/v1/maintenance/official-error-entries/{$b->id}")->assertOk()->json('data');
        $this->assertSame(['FS-531', 'FS-612'], array_column($detailA['applicabilities'], 'scope_label'));
        $this->assertSame(['FS-532'], array_column($detailB['applicabilities'], 'scope_label'));
        $this->assertSame(['Fixture part A'], array_column($detailA['parts'], 'part_name'));
        $this->assertSame(['Fixture part B'], array_column($detailB['parts'], 'part_name'));
        $this->assertSame(['Fixture solution A'], array_column($detailA['steps'], 'instruction'));
        $this->assertSame(['Fixture solution B'], array_column($detailB['steps'], 'instruction'));
        $this->assertSame(['Fixture reference A'], array_column($detailA['references'], 'reference_value'));
        $this->assertSame(['Fixture reference B'], array_column($detailB['references'], 'reference_value'));
    }

    public function test_technician_search_normalizes_safe_code_formats_and_keeps_variants_distinct(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        MaintenanceOfficialKnowledgeFixture::c3913($f['document']);
        MaintenanceOfficialKnowledgeFixture::c1103Variants($f['document']);
        MaintenanceOfficialKnowledgeFixture::specialCodeFamilies($f['document']);

        foreach (['3913', 'C3913', 'C-3913', 'c3913', 'c-3913'] as $input) {
            $this->actingAs($user)->getJson('/api/v1/maintenance/official-error-entries?search='.$input)
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.code', 'C-3913');
        }

        $variants = $this->actingAs($user)->getJson('/api/v1/maintenance/official-error-entries?search=C1103')
            ->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertSame(['FS-531_FS-612', 'FS-532'], array_column($variants, 'variant_key'));

        $this->actingAs($user)->getJson('/api/v1/maintenance/official-error-entries?search=cc152')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'C-C152');
    }

    public function test_partial_search_is_bounded_paginated_and_does_not_guess_unknown_codes(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        MaintenanceOfficialKnowledgeFixture::c3913($f['document']);
        MaintenanceOfficialKnowledgeFixture::c1103Variants($f['document']);

        $this->actingAs($user)->getJson('/api/v1/maintenance/official-error-entries?search=110&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2);

        $this->actingAs($user)->getJson('/api/v1/maintenance/official-error-entries?search=C9999')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($user)->getJson('/api/v1/maintenance/official-error-entries?search=C%2F3913')
            ->assertUnprocessable();
    }

    public function test_exact_document_code_and_normalized_variant_identity_cannot_be_duplicated(): void
    {
        $f = $this->fixture();
        [$a] = MaintenanceOfficialKnowledgeFixture::c1103Variants($f['document']);

        $this->assertSame('FS-531_FS-612', $a->variant_key);
        $this->expectException(QueryException::class);
        MaintenanceOfficialErrorEntry::create([
            'document_id' => $f['document']->id,
            'code' => 'c-1103',
            'variant_key' => 'FS-531 / FS-612',
            'raw_source_text' => 'Fixture duplicate identity.',
        ]);
    }

    public function test_special_code_families_and_minimal_nullable_entry_are_supported(): void
    {
        $f = $this->fixture();
        $entries = MaintenanceOfficialKnowledgeFixture::specialCodeFamilies($f['document']);

        $this->assertSame(['C-C152', 'C-C170', 'C-D010'], MaintenanceOfficialErrorEntry::whereIn('code', array_keys($entries))->orderBy('code')->pluck('code')->all());
        $minimal = $entries['C-C170']->fresh(['steps']);
        foreach (['classification', 'cause', 'alert_measure', 'correction', 'warning', 'note', 'isolation_dipsw', 'detached_control', 'source_page_start', 'source_page_end'] as $field) {
            $this->assertNull($minimal->{$field});
        }
        $this->assertCount(1, $minimal->steps);
    }

    public function test_complex_fixture_preserves_fourteen_steps_warning_dipsw_and_typed_step_references(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $entry = MaintenanceOfficialKnowledgeFixture::complexC3911Shape($f['document']);

        $detail = $this->actingAs($user)->getJson("/api/v1/maintenance/official-error-entries/{$entry->id}")
            ->assertOk()
            ->assertJsonPath('data.warning', 'Fixture safety warning')
            ->assertJsonPath('data.isolation_dipsw', 'Fixture DIPSW instruction')
            ->json('data');
        $this->assertCount(3, $detail['parts']);
        $this->assertCount(14, $detail['steps']);
        $this->assertSame(range(1, 14), array_column($detail['steps'], 'step_number'));
        $this->assertSame('Fixture step 01', $detail['steps'][0]['instruction']);
        $this->assertSame('Fixture step 14', $detail['steps'][13]['instruction']);
        $references = collect($detail['references'])->keyBy('reference_type');
        $this->assertSame(3, $references['WIRING_DIAGRAM']['step_number']);
        $this->assertSame('Fixture wiring reference', $references['WIRING_DIAGRAM']['reference_value']);
        $this->assertSame(7, $references['IO_CHECK']['step_number']);
        $this->assertSame('Fixture I/O reference', $references['IO_CHECK']['reference_value']);
    }

    public function test_step_level_reference_rejects_a_step_from_another_entry(): void
    {
        $f = $this->fixture();
        [$a] = MaintenanceOfficialKnowledgeFixture::c1103Variants($f['document']);

        $this->expectException(InvalidArgumentException::class);
        MaintenanceOfficialErrorReference::create([
            'error_entry_id' => $a->id,
            'step_number' => 99,
            'reference_type' => 'IO_CHECK',
            'reference_value' => 'Fixture invalid cross-step reference',
        ]);
    }

    public function test_document_derived_tenant_isolation_global_visibility_and_authentication(): void
    {
        $f = $this->fixture();
        $home = $this->member($f['home']);
        $attacker = $this->member($f['foreign']);
        $tenantEntry = MaintenanceOfficialKnowledgeFixture::c3913($f['document']);
        $globalDocument = MaintenanceDocument::create(['title' => 'Global Fixture Manual', 'file_reference' => 'global.pdf', 'status' => 'PUBLISHED', 'is_active' => true]);
        $globalEntry = MaintenanceOfficialErrorEntry::create(['document_id' => $globalDocument->id, 'code' => 'C-G001', 'variant_key' => 'GLOBAL', 'raw_source_text' => 'Global fixture evidence']);

        $this->getJson("/api/v1/maintenance/official-error-entries/{$globalEntry->id}")->assertUnauthorized();
        $this->actingAs($home)->getJson("/api/v1/maintenance/official-error-entries/{$tenantEntry->id}")->assertOk();
        $this->actingAs($attacker)->getJson("/api/v1/maintenance/official-error-entries/{$tenantEntry->id}")->assertNotFound();
        $foreignList = $this->actingAs($attacker)->getJson('/api/v1/maintenance/official-error-entries')->assertOk()->json('data');
        $this->assertFalse(collect($foreignList)->contains('id', $tenantEntry->id));
        $this->assertTrue(collect($foreignList)->contains('id', $globalEntry->id));
    }

    public function test_filters_are_scoped_and_mutation_routes_do_not_exist(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $entry = MaintenanceOfficialKnowledgeFixture::c3913($f['document']);
        MaintenanceOfficialKnowledgeFixture::specialCodeFamilies($f['document']);

        $this->actingAs($user)->getJson('/api/v1/maintenance/official-error-entries?document_id='.$f['document']->id)
            ->assertOk()->assertJsonCount(4, 'data');
        $this->actingAs($user)->getJson('/api/v1/maintenance/official-error-entries?machine_model_id='.$f['model']->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $entry->id);
        $this->actingAs($user)->postJson('/api/v1/maintenance/official-error-entries', [])->assertMethodNotAllowed();
        $this->actingAs($user)->patchJson("/api/v1/maintenance/official-error-entries/{$entry->id}", [])->assertMethodNotAllowed();
        $this->actingAs($user)->deleteJson("/api/v1/maintenance/official-error-entries/{$entry->id}")->assertMethodNotAllowed();
    }

    public function test_official_knowledge_blocks_source_document_deletion_and_leaves_legacy_rows_unchanged(): void
    {
        $f = $this->fixture();
        $owner = $this->member($f['home']);
        $legacyCode = MachineErrorCode::create(['account_id' => $f['home']->id, 'machine_model_id' => $f['model']->id, 'code' => 'C-0001', 'title' => 'Legacy fixture']);
        MaintenanceErrorSolution::create(['machine_error_code_id' => $legacyCode->id, 'step_number' => 1, 'instruction' => 'Legacy solution']);
        $legacyCounts = [MachineErrorCode::count(), MaintenanceErrorSolution::count()];
        MaintenanceOfficialKnowledgeFixture::c3913($f['document']);

        $this->actingAs($owner)->deleteJson("/api/v1/maintenance/documents/{$f['document']->id}")->assertStatus(409);
        $this->assertDatabaseHas('maintenance_documents', ['id' => $f['document']->id]);
        $this->assertSame($legacyCounts, [MachineErrorCode::count(), MaintenanceErrorSolution::count()]);
    }

    public function test_document_fk_restricts_deletion_outside_the_http_guard(): void
    {
        $f = $this->fixture();
        MaintenanceOfficialKnowledgeFixture::c3913($f['document']);

        $this->expectException(QueryException::class);
        $f['document']->delete();
    }
}
