<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\MachineModel;
use App\Models\MaintenanceDocument;
use App\Models\MaintenanceOfficialErrorEntry;
use App\Models\Manufacturer;
use App\Models\User;
use App\Services\AssistedKnowledge\AssistedKnowledgeService;
use App\Services\AssistedKnowledge\AssistedKnowledgeValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\Fixtures\MaintenanceOfficialKnowledgeFixture;
use Tests\TestCase;

class MaintenanceAssistedKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $home = Account::create(['code' => 'HOME', 'name' => 'Home', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $foreign = Account::create(['code' => 'FOREIGN', 'name' => 'Foreign', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $manufacturer = Manufacturer::create(['code' => 'KM', 'name' => 'Konica Minolta']);
        $model = MachineModel::create(['manufacturer_id' => $manufacturer->id, 'model_code' => 'C1070', 'name' => 'C1070']);
        $document = MaintenanceDocument::create(['account_id' => $home->id, 'manufacturer_id' => $manufacturer->id, 'machine_model_id' => $model->id, 'title' => 'Manual', 'document_type' => 'SERVICE_MANUAL', 'file_reference' => 'fixture.pdf', 'status' => 'PUBLISHED', 'is_active' => true]);

        return compact('home', 'foreign', 'document');
    }

    private function member(Account $account): User
    {
        $user = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active', 'accepted_at' => now()]);

        return $user;
    }

    private function ready(MaintenanceOfficialErrorEntry $entry): MaintenanceOfficialErrorEntry
    {
        $entry->update(['normalized_digest' => hash('sha256', 'normalized-'.$entry->id)]);

        return $entry->fresh('steps');
    }

    private function payload(MaintenanceOfficialErrorEntry $entry): array
    {
        return [
            'official_entry_id' => $entry->id,
            'language' => 'id',
            'source_normalized_digest' => $entry->normalized_digest,
            'error_code' => $entry->code,
            'classification_translation' => $entry->classification === null ? null : 'ID '.$entry->classification,
            'classification_simplified' => $entry->classification === null ? null : 'Mudah '.$entry->classification,
            'cause_translation' => $entry->cause === null ? null : 'ID '.$entry->cause,
            'cause_simplified' => $entry->cause === null ? null : 'Mudah '.$entry->cause,
            'warning_translation' => $entry->warning === null ? null : 'ID '.$entry->warning,
            'warning_simplified' => $entry->warning === null ? null : 'WAJIB '.$entry->warning,
            'solution_steps' => $entry->steps->map(fn ($step) => [
                'official_step_id' => $step->id,
                'official_order' => $step->step_number,
                'translation' => 'ID '.$step->instruction,
                'simplified' => 'Mudah '.$step->instruction,
            ])->values()->all(),
        ];
    }

    private function persist(MaintenanceOfficialErrorEntry $entry, array $payload): void
    {
        app(AssistedKnowledgeService::class)->persistValid($entry, $payload, ['provider' => 'test', 'model' => 'test', 'revision' => 'v1'], 1);
    }

    public function test_additive_schema_maps_assisted_steps_to_official_steps_without_changing_official_rows(): void
    {
        $this->assertTrue(Schema::hasColumns('maintenance_assisted_error_entries', ['official_error_entry_id', 'language', 'content_version', 'source_normalized_digest', 'status']));
        $this->assertTrue(Schema::hasColumns('maintenance_assisted_error_steps', ['assisted_error_entry_id', 'official_step_id', 'official_order']));
        $f = $this->fixture();
        $entry = $this->ready(MaintenanceOfficialKnowledgeFixture::c3913($f['document']));
        $before = DB::table('maintenance_official_error_entries')->where('id', $entry->id)->first();
        $stepsBefore = DB::table('maintenance_official_error_steps')->where('error_entry_id', $entry->id)->orderBy('step_number')->get()->toJson();
        $this->persist($entry, $this->payload($entry));
        $this->assertEquals($before, DB::table('maintenance_official_error_entries')->where('id', $entry->id)->first());
        $this->assertSame($stepsBefore, DB::table('maintenance_official_error_steps')->where('error_entry_id', $entry->id)->orderBy('step_number')->get()->toJson());
        $this->assertSame($entry->steps->pluck('id')->all(), DB::table('maintenance_assisted_error_steps')->orderBy('official_order')->pluck('official_step_id')->all());
    }

    public function test_validator_rejects_digest_code_step_count_order_and_technical_token_failures(): void
    {
        $f = $this->fixture();
        $entry = $this->ready(MaintenanceOfficialKnowledgeFixture::c3913($f['document']));
        $validator = app(AssistedKnowledgeValidator::class);

        $payload = $this->payload($entry);
        $payload['source_normalized_digest'] = str_repeat('0', 64);
        $this->assertContains('source_digest_mismatch', $validator->validate($entry, $payload));
        $payload = $this->payload($entry);
        $payload['error_code'] = 'C-9999';
        $this->assertContains('error_code_mismatch', $validator->validate($entry, $payload));
        $payload = $this->payload($entry);
        array_pop($payload['solution_steps']);
        $this->assertContains('solution_step_count_mismatch', $validator->validate($entry, $payload));
        $payload = $this->payload($entry);
        $payload['solution_steps'][] = $payload['solution_steps'][0];
        $this->assertContains('solution_step_count_mismatch', $validator->validate($entry, $payload));
        $payload = $this->payload($entry);
        $payload['solution_steps'][0]['official_order'] = 2;
        $this->assertContains('solution_step_mapping_mismatch:1', $validator->validate($entry, $payload));
        $payload = $this->payload($entry);
        $payload['solution_steps'][2]['translation'] = 'Kabel harus diperiksa.';
        $this->assertContains('solution_step_technical_token_mismatch:3:translation', $validator->validate($entry, $payload));
    }

    public function test_warning_omission_and_malformed_output_fail_closed(): void
    {
        $f = $this->fixture();
        $entry = $this->ready(MaintenanceOfficialKnowledgeFixture::complexC3911Shape($f['document']));
        $validator = app(AssistedKnowledgeValidator::class);
        $payload = $this->payload($entry);
        $payload['warning_translation'] = null;
        $this->assertContains('warning_missing', $validator->validate($entry, $payload));
        $payload = $this->payload($entry);
        $payload['solution_steps'] = 'malformed';
        $this->assertContains('solution_steps_malformed', $validator->validate($entry, $payload));
        $this->expectException(InvalidArgumentException::class);
        $this->persist($entry, $payload);
    }

    public function test_valid_assisted_content_is_returned_but_stale_or_missing_content_falls_back_to_original(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $entry = $this->ready(MaintenanceOfficialKnowledgeFixture::c3913($f['document']));
        $this->actingAs($user)->getJson("/api/v1/maintenance/official-error-entries/{$entry->id}")->assertOk()->assertJsonPath('data.assisted', null);
        $this->persist($entry, $this->payload($entry));
        $this->actingAs($user)->getJson("/api/v1/maintenance/official-error-entries/{$entry->id}")->assertOk()->assertJsonPath('data.assisted.language', 'id')->assertJsonCount(5, 'data.assisted.steps');
        $entry->update(['normalized_digest' => str_repeat('f', 64)]);
        $this->actingAs($user)->getJson("/api/v1/maintenance/official-error-entries/{$entry->id}")->assertOk()->assertJsonPath('data.assisted', null)->assertJsonCount(5, 'data.steps');
    }

    public function test_variants_are_independent_and_assisted_api_keeps_auth_and_tenant_visibility(): void
    {
        $f = $this->fixture();
        $home = $this->member($f['home']);
        $foreign = $this->member($f['foreign']);
        [$a, $b] = MaintenanceOfficialKnowledgeFixture::c1103Variants($f['document']);
        $a = $this->ready($a);
        $b = $this->ready($b);
        $this->persist($a, $this->payload($a));
        $this->persist($b, $this->payload($b));
        $this->assertSame(2, DB::table('maintenance_assisted_error_entries')->count());
        $this->getJson("/api/v1/maintenance/official-error-entries/{$a->id}")->assertUnauthorized();
        $this->actingAs($foreign)->getJson("/api/v1/maintenance/official-error-entries/{$a->id}")->assertNotFound();
        $this->actingAs($home)->getJson("/api/v1/maintenance/official-error-entries/{$a->id}")->assertOk()->assertJsonPath('data.variant_key', 'FS-531_FS-612');
        $this->actingAs($home)->getJson("/api/v1/maintenance/official-error-entries/{$b->id}")->assertOk()->assertJsonPath('data.variant_key', 'FS-532');
    }

    public function test_provider_failure_does_not_affect_official_detail(): void
    {
        $f = $this->fixture();
        $user = $this->member($f['home']);
        $entry = $this->ready(MaintenanceOfficialKnowledgeFixture::c3913($f['document']));
        config(['maintenance_assisted.provider.endpoint' => null, 'maintenance_assisted.provider.key' => null]);
        try {
            app(AssistedKnowledgeService::class)->generate($entry);
            $this->fail('Provider failure was expected.');
        } catch (RuntimeException) {
            $this->actingAs($user)->getJson("/api/v1/maintenance/official-error-entries/{$entry->id}")->assertOk()->assertJsonPath('data.assisted', null)->assertJsonPath('data.code', 'C-3913');
        }
    }
}
