<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Branch;
use App\Models\Machine;
use App\Models\MachineModel;
use App\Models\Manufacturer;
use App\Models\PlatformUserPrivilege;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MachineMasterCrudTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $a = Account::create(['code' => 'MM', 'name' => 'Machine Masters', 'default_timezone' => 'Asia/Jakarta']);
        $b = $a->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'timezone' => 'Asia/Jakarta']);
        $u = User::factory()->create(['status' => 'active']);
        AccountMembership::create(['account_id' => $a->id, 'user_id' => $u->id, 'role' => 'owner', 'status' => 'active']);
        $superuser = User::factory()->create(['status' => 'active']);
        PlatformUserPrivilege::create(['user_id' => $superuser->id, 'role' => 'superuser', 'is_active' => true]);
        $konica = Manufacturer::create(['code' => 'km', 'name' => 'Konica Minolta']);
        $xerox = Manufacturer::create(['code' => 'xerox', 'name' => 'Xerox']);
        $c1070 = MachineModel::create(['manufacturer_id' => $xerox->id, 'model_code' => 'c1070', 'name' => 'AccurioPress C1070']);

        return compact('a', 'b', 'u', 'superuser', 'konica', 'xerox', 'c1070');
    }

    public function test_superuser_creates_and_edits_manufacturer_including_notes(): void
    {
        $f = $this->fixture();
        $created = $this->actingAs($f['superuser'])->postJson('/api/v1/manufacturers', ['code' => 'ricoh', 'name' => 'Ricoh'])
            ->assertCreated()->json('data');
        $this->actingAs($f['superuser'])->putJson('/api/v1/manufacturers/'.$created['id'], ['code' => 'ricoh', 'name' => 'Ricoh Co', 'notes' => 'Renamed after audit'])
            ->assertOk()->assertJsonPath('data.name', 'Ricoh Co')->assertJsonPath('data.notes', 'Renamed after audit');
    }

    public function test_non_superuser_is_denied_manufacturer_write(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['u'])->postJson('/api/v1/manufacturers', ['code' => 'x', 'name' => 'X'])->assertForbidden();
        $this->actingAs($f['u'])->putJson('/api/v1/manufacturers/'.$f['konica']->id, ['code' => 'km', 'name' => 'Renamed'])->assertForbidden();
    }

    public function test_duplicate_manufacturer_code_rejected_on_create_and_edit(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['superuser'])->postJson('/api/v1/manufacturers', ['code' => 'km', 'name' => 'Duplicate'])->assertStatus(409);
        $this->actingAs($f['superuser'])->putJson('/api/v1/manufacturers/'.$f['xerox']->id, ['code' => 'km', 'name' => 'Xerox'])->assertStatus(409);
    }

    public function test_manufacturer_with_active_models_cannot_be_archived(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['superuser'])->patchJson('/api/v1/manufacturers/'.$f['xerox']->id.'/status', ['is_active' => false])->assertStatus(409);
    }

    public function test_superuser_creates_and_edits_model_and_reassigns_manufacturer(): void
    {
        $f = $this->fixture();
        $created = $this->actingAs($f['superuser'])->postJson('/api/v1/machine-models', ['manufacturer_id' => $f['xerox']->id, 'model_code' => 'versant180', 'name' => 'Xerox Versant 180', 'description' => 'Production press'])
            ->assertCreated()->json('data');
        $this->assertSame((string) $f['xerox']->id, $created['manufacturer_id']);

        $updated = $this->actingAs($f['superuser'])->putJson('/api/v1/machine-models/'.$f['c1070']->id, [
            'manufacturer_id' => $f['konica']->id, 'model_code' => 'c1070', 'name' => 'AccurioPress C1070', 'machine_category' => 'digital_a3', 'color_capability' => 'color', 'description' => 'Corrected migrated manufacturer link',
        ])->assertOk()->json('data');
        $this->assertSame((string) $f['konica']->id, $updated['manufacturer_id']);
        $this->assertSame('Konica Minolta', $updated['manufacturer']['name']);
        $this->assertSame('Corrected migrated manufacturer link', $f['c1070']->fresh()->description);
    }

    public function test_non_superuser_is_denied_model_write(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['u'])->postJson('/api/v1/machine-models', ['manufacturer_id' => $f['xerox']->id, 'model_code' => 'new', 'name' => 'New'])->assertForbidden();
        $this->actingAs($f['u'])->putJson('/api/v1/machine-models/'.$f['c1070']->id, ['manufacturer_id' => $f['konica']->id, 'model_code' => 'c1070', 'name' => 'AccurioPress C1070'])->assertForbidden();
    }

    public function test_duplicate_model_code_within_manufacturer_scope_rejected_on_create_and_edit(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['superuser'])->postJson('/api/v1/machine-models', ['manufacturer_id' => $f['xerox']->id, 'model_code' => 'c1070', 'name' => 'Duplicate'])->assertStatus(409);
        $other = MachineModel::create(['manufacturer_id' => $f['xerox']->id, 'model_code' => 'versant80', 'name' => 'Versant 80']);
        $this->actingAs($f['superuser'])->putJson('/api/v1/machine-models/'.$other->id, ['manufacturer_id' => $f['xerox']->id, 'model_code' => 'c1070', 'name' => 'Versant 80'])->assertStatus(409);
    }

    public function test_archiving_a_referenced_model_preserves_existing_machines(): void
    {
        $f = $this->fixture();
        $machine = $f['b']->machines()->create(['account_id' => $f['a']->id, 'machine_model_id' => $f['c1070']->id, 'machine_code' => 'CG-01', 'display_name' => 'C1070', 'status' => 'active']);
        $this->actingAs($f['superuser'])->patchJson('/api/v1/machine-models/'.$f['c1070']->id.'/status', ['is_active' => false])->assertOk();
        $this->assertFalse($f['c1070']->fresh()->is_active);
        $this->assertSame('active', $machine->fresh()->status);
        $this->assertSame((string) $f['c1070']->id, (string) $machine->fresh()->machine_model_id);
    }

    public function test_migrated_model_edit_flow_matches_newly_created_model_flow(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['superuser'])->getJson('/api/v1/machine-models?account_id='.$f['a']->id)->assertOk();
        $this->actingAs($f['superuser'])->putJson('/api/v1/machine-models/'.$f['c1070']->id, ['manufacturer_id' => $f['konica']->id, 'model_code' => 'c1070', 'name' => 'AccurioPress C1070'])
            ->assertOk()->assertJsonPath('data.manufacturer.name', 'Konica Minolta');
    }
}
