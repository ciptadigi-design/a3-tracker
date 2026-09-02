<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CanonicalConfigSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_emits_exact_16_create_plan_and_rolls_everything_back(): void
    {
        $this->seedProductionBaseline();
        $peopleBefore = $this->fingerprint('operational_people');
        $historyBefore = $this->fingerprint('counter_readings');

        $this->artisan('a3:sync-canonical-config', ['--dry-run' => true, '--expect-create' => '16'])
            ->expectsOutputToContain('WRITE_PLAN_BEGIN')
            ->expectsOutputToContain('CONFIG_CREATE=16')
            ->expectsOutputToContain('CONFIG_UPDATE=0')
            ->expectsOutputToContain('CONFIG_DELETE=0')
            ->expectsOutputToContain('DEV_UUID_LEAKAGE=0')
            ->expectsOutputToContain('AUTH_USER_CREATION=0')
            ->expectsOutputToContain('PERSON_IDENTITY_REWRITE=0')
            ->expectsOutputToContain('OPERATIONAL_FACT_WRITES=0')
            ->expectsOutputToContain('FAKE_STOCK_CREATION=0')
            ->expectsOutputToContain('FAKE_LIFECYCLE_CREATION=0')
            ->expectsOutputToContain('LEGACY_GRAHA_OPERATIONAL_RECORDS=0')
            ->expectsOutputToContain('NEW_WRITES=0')
            ->expectsOutputToContain('CONFIG_SYNC_DRY_RUN=PASS')
            ->assertSuccessful();

        $this->assertDatabaseMissing('branches', ['code' => 'CG-GRH']);
        $this->assertDatabaseCount('counter_types', 1);
        $this->assertDatabaseCount('inventory_locations', 0);
        $this->assertDatabaseCount('inventory_items', 0);
        $this->assertSame($peopleBefore, $this->fingerprint('operational_people'));
        $this->assertSame($historyBefore, $this->fingerprint('counter_readings'));
    }

    public function test_apply_is_idempotent_and_never_creates_operational_or_auth_rows(): void
    {
        $this->seedProductionBaseline();

        $this->artisan('a3:sync-canonical-config', [
            '--apply' => true, '--confirm' => 'APPLY_MINIMAL_SAFE_DAY1', '--expect-create' => '16',
        ])->expectsOutputToContain('CONFIG_CREATE=16')->expectsOutputToContain('NEW_WRITES=16')->assertSuccessful();

        $this->assertDatabaseHas('branches', ['id' => config('canonical_day1.creates.branch.id'), 'code' => 'CG-GRH', 'name' => 'Graha']);
        $this->assertDatabaseCount('counter_types', 4);
        $this->assertDatabaseCount('inventory_locations', 1);
        $this->assertDatabaseCount('inventory_items', 10);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('operational_people', 1);
        $this->assertDatabaseCount('counter_readings', 1);
        $this->assertDatabaseCount('component_lifecycles', 0);
        $this->assertDatabaseCount('inventory_movements', 0);

        $this->artisan('a3:sync-canonical-config', [
            '--apply' => true, '--confirm' => 'APPLY_MINIMAL_SAFE_DAY1', '--expect-create' => '0',
        ])->expectsOutputToContain('CONFIG_CREATE=0')->expectsOutputToContain('NEW_WRITES=0')->assertSuccessful();

        $this->assertDatabaseCount('branches', 2);
        $this->assertDatabaseCount('counter_types', 4);
        $this->assertDatabaseCount('inventory_items', 10);
    }

    public function test_conflicting_business_key_fails_closed_without_partial_writes(): void
    {
        $this->seedProductionBaseline();
        DB::table('branches')->insert([
            'id' => '11111111-1111-4111-8111-111111111111',
            'account_id' => config('canonical_day1.production.account.id'),
            'code' => 'CG-GRH', 'name' => 'Wrong Graha', 'timezone' => 'UTC',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $message = null;
        try {
            $this->artisan('a3:sync-canonical-config', ['--dry-run' => true])->execute();
        } catch (\RuntimeException $exception) {
            $message = $exception->getMessage();
        }
        $this->assertStringContainsString('conflicts', (string) $message);
        $this->assertDatabaseCount('counter_types', 1);
        $this->assertDatabaseCount('inventory_items', 0);
    }

    public function test_unclassified_component_linked_item_stops_instead_of_creating_a_duplicate(): void
    {
        $this->seedProductionBaseline();
        $item = config('canonical_day1.creates.inventory_items.0');
        DB::table('inventory_items')->insert([
            'id' => '22222222-2222-4222-8222-222222222222',
            'account_id' => config('canonical_day1.production.account.id'),
            'component_id' => $item['component_id'], 'sku' => 'UNREVIEWED', 'name' => 'Unreviewed',
            'unit' => 'pcs', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $message = null;
        try {
            $this->artisan('a3:sync-canonical-config', ['--dry-run' => true])->execute();
        } catch (\RuntimeException $exception) {
            $message = $exception->getMessage();
        }
        $this->assertStringContainsString('unclassified linked item', (string) $message);
        $this->assertDatabaseMissing('branches', ['code' => 'CG-GRH']);
        $this->assertDatabaseCount('inventory_items', 1);
    }

    private function seedProductionBaseline(): void
    {
        $p = config('canonical_day1.production');
        DB::table('users')->insert(['id' => $p['master_user_id'], 'name' => 'Master', 'email' => 'master@example.test', 'username' => 'master', 'password' => 'x', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('accounts')->insert($p['account'] + ['default_timezone' => 'Asia/Jakarta', 'default_currency' => 'IDR', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branches')->insert(['id' => $p['tuparev']['id'], 'account_id' => $p['account']['id'], 'code' => $p['tuparev']['code'], 'name' => $p['tuparev']['name'], 'timezone' => 'Asia/Jakarta', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('manufacturers')->insert(['id' => '11111111-1111-4111-8111-111111111112', 'account_id' => null, 'code' => 'konica_minolta', 'name' => 'Konica Minolta', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('machine_models')->insert(['id' => $p['machine_model_id'], 'account_id' => null, 'manufacturer_id' => '11111111-1111-4111-8111-111111111112', 'model_code' => 'accuriopress_c1070', 'name' => 'AccurioPress C1070', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('machines')->insert(['id' => $p['machine']['id'], 'account_id' => $p['account']['id'], 'branch_id' => $p['tuparev']['id'], 'machine_model_id' => $p['machine_model_id'], 'machine_code' => $p['machine']['code'], 'display_name' => 'C1070', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('operational_people')->insert(['id' => '33333333-3333-4333-8333-333333333333', 'account_id' => $p['account']['id'], 'name' => 'Dhea', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('operational_person_branches')->insert(['id' => '44444444-4444-4444-8444-444444444444', 'account_id' => $p['account']['id'], 'person_id' => '33333333-3333-4333-8333-333333333333', 'branch_id' => $p['tuparev']['id'], 'is_active' => true, 'can_record_counter' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inventory_suppliers')->insert(['id' => '55555555-5555-4555-8555-555555555555', 'account_id' => $p['account']['id'], 'code' => 'LEG-JFP', 'name' => 'JFP', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach (config('canonical_day1.creates.inventory_items') as $index => $item) {
            DB::table('component_catalogs')->insert(['id' => $item['component_id'], 'account_id' => $p['account']['id'], 'code' => $item['component_code'], 'name' => $item['name'], 'category' => $item['category'], 'tracking_method' => 'counter_based', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('machine_components')->insert(['id' => sprintf('66666666-6666-4666-8%03d-%012d', $index, $index), 'account_id' => $p['account']['id'], 'machine_id' => $p['machine']['id'], 'component_id' => $item['component_id'], 'slot_code' => $item['component_code'], 'source_type' => 'configured', 'status' => 'configured', 'active_key' => 'active', 'display_order' => $index, 'tracking_method' => 'counter_based', 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('counter_readings')->insert(['id' => '77777777-7777-4777-8777-777777777777', 'account_id' => $p['account']['id'], 'machine_id' => $p['machine']['id'], 'counter_type_id' => '00000000-0000-0000-0000-000000000001', 'reading_value' => 100, 'observed_at' => now(), 'operator_person_id' => '33333333-3333-4333-8333-333333333333', 'source' => 'manual', 'status' => 'effective', 'client_request_id' => '88888888-8888-4888-8888-888888888888', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function fingerprint(string $table): string
    {
        return hash('sha256', json_encode(DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(), JSON_THROW_ON_ERROR));
    }
}
