<?php
namespace Tests\Feature;
use App\Models\User; use Illuminate\Foundation\Testing\RefreshDatabase; use Illuminate\Support\Facades\DB; use Tests\TestCase;
class FoundationApiTest extends TestCase { use RefreshDatabase;
 public function test_health_and_version_are_safe(): void { $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('data.status','ok'); $this->getJson('/api/v1/version')->assertOk()->assertJsonStructure(['data'=>['application','backend','git_sha','schema_batch']]); }
 public function test_login_me_and_logout(): void { $u=User::factory()->create(['password'=>'secret-password']); $this->postJson('/api/v1/auth/login',['identifier'=>$u->email,'password'=>'secret-password'])->assertOk(); $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.user.id',$u->id); $this->postJson('/api/v1/auth/logout')->assertNoContent(); $this->getJson('/api/v1/me')->assertUnauthorized(); }
 public function test_anonymous_me_is_denied_and_uuid_contract_is_present(): void { $this->getJson('/api/v1/me')->assertUnauthorized(); $this->assertContains(strtolower(DB::getSchemaBuilder()->getColumnType('users','id')), ['char','varchar']); }
 public function test_anonymous_api_without_json_accept_is_still_a_deterministic_401(): void { $this->get('/api/v1/me')->assertUnauthorized()->assertJson(['message'=>'Unauthenticated.','errors'=>[]]); }
}
