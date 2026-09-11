<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * M2.17.4 Scope A: the reported "CSRF token mismatch" on Supplier Save was root-caused
 * to deployment-invalidated file sessions (see docs), not a defect in the CSRF check
 * itself - so this suite proves the CSRF/session pipeline behaves correctly end to end
 * through the REAL cookie/token flow the SPA actually uses (GET /sanctum/csrf-cookie,
 * then replay the XSRF-TOKEN cookie's value as both a cookie and the X-XSRF-TOKEN
 * header on every mutating request), rather than only through actingAs(), which
 * bypasses CSRF entirely and would not have caught this class of bug.
 */
class M2_17_4_SessionCsrfTest extends TestCase
{
    use RefreshDatabase;

    // VerifyCsrfToken::handle() unconditionally short-circuits to "pass" whenever
    // Application::runningUnitTests() is true (APP_ENV=testing), specifically so tests
    // don't need CSRF plumbing. That is exactly the check this suite exists to exercise
    // for real, so it is disabled here and restored in tearDown() - every other test
    // file is unaffected since PHPUnit gives each test class/case its own instance.
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('env', 'local');
    }

    protected function tearDown(): void
    {
        $this->app->instance('env', 'testing');
        parent::tearDown();
    }

    private function bootstrapCsrf(): array
    {
        $response = $this->getJson('/sanctum/csrf-cookie')->assertNoContent();
        $cookies = $response->headers->getCookies();
        $xsrf = collect($cookies)->first(fn ($c) => $c->getName() === 'XSRF-TOKEN');
        $this->assertNotNull($xsrf, 'csrf-cookie response did not set XSRF-TOKEN.');

        $this->withCookie('XSRF-TOKEN', $xsrf->getValue());

        return ['cookie' => $xsrf, 'header' => $xsrf->getValue()];
    }

    private function rotateCsrfFrom($response): void
    {
        $cookies = $response->headers->getCookies();
        $rotated = collect($cookies)->first(fn ($c) => $c->getName() === 'XSRF-TOKEN');
        if ($rotated) {
            $this->withCookie('XSRF-TOKEN', $rotated->getValue());
        }
        $session = collect($cookies)->first(fn ($c) => $c->getName() === config('session.cookie'));
        if ($session) {
            $this->withCookie(config('session.cookie'), $session->getValue());
        }
    }

    private function currentXsrfHeader(): string
    {
        return $this->defaultCookies['XSRF-TOKEN'];
    }

    public function test_real_login_then_csrf_protected_supplier_mutation_succeeds(): void
    {
        $account = Account::create(['code' => 'CG', 'name' => 'Cipta Grafika', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $user = User::create(['name' => 'Admin', 'email' => 'admin@m2174.test', 'username' => 'admin_csrf', 'password' => Hash::make('password123'), 'status' => 'active']);
        AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'admin', 'status' => 'active', 'accepted_at' => now()]);

        $this->bootstrapCsrf();

        $login = $this->withHeader('X-XSRF-TOKEN', $this->currentXsrfHeader())
            ->postJson('/api/v1/auth/login', ['identifier' => 'admin_csrf', 'password' => 'password123'])
            ->assertOk();
        // Login regenerates the session/token (AuthController calls regenerate()); the
        // SPA's real browser session picks up the rotated Set-Cookie automatically, so the
        // test must replay it the same way rather than reusing the pre-login token.
        $this->rotateCsrfFrom($login);

        $this->withHeader('X-XSRF-TOKEN', $this->currentXsrfHeader())
            ->postJson('/api/v1/inventory/suppliers', [
                'account_id' => $account->id, 'code' => 'JFP', 'name' => 'Just_ForPrint',
            ])
            ->assertOk()
            ->assertJsonPath('data.code', 'JFP');
    }

    public function test_mutation_with_stale_csrf_token_is_rejected_with_419_and_does_not_apply(): void
    {
        $account = Account::create(['code' => 'CG', 'name' => 'Cipta Grafika', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);
        $user = User::create(['name' => 'Admin', 'email' => 'admin2@m2174.test', 'username' => 'admin_csrf_stale', 'password' => Hash::make('password123'), 'status' => 'active']);
        AccountMembership::create(['account_id' => $account->id, 'user_id' => $user->id, 'role' => 'admin', 'status' => 'active', 'accepted_at' => now()]);

        $this->bootstrapCsrf();
        $login = $this->withHeader('X-XSRF-TOKEN', $this->currentXsrfHeader())
            ->postJson('/api/v1/auth/login', ['identifier' => 'admin_csrf_stale', 'password' => 'password123'])
            ->assertOk();

        $staleHeader = $this->currentXsrfHeader();
        $this->rotateCsrfFrom($login);
        $this->assertNotSame($staleHeader, $this->currentXsrfHeader(), 'Login should rotate the CSRF token; this test needs the pre-login token to actually be stale.');

        // Simulate a browser tab that still holds the pre-login (now stale) token, exactly
        // the scenario a deployment-invalidated session reproduces: session cookie is
        // current, but the X-XSRF-TOKEN header carries a token that no longer matches.
        $this->withHeader('X-XSRF-TOKEN', $staleHeader)
            ->postJson('/api/v1/inventory/suppliers', ['account_id' => $account->id, 'code' => 'JFP', 'name' => 'Just_ForPrint'])
            ->assertStatus(419);

        $this->assertDatabaseMissing('inventory_suppliers', ['code' => 'JFP']);
    }

    public function test_unauthenticated_supplier_mutation_is_rejected_with_401(): void
    {
        $account = Account::create(['code' => 'CG', 'name' => 'Cipta Grafika', 'default_timezone' => 'Asia/Jakarta', 'status' => 'active']);

        $this->bootstrapCsrf();
        $this->withHeader('X-XSRF-TOKEN', $this->currentXsrfHeader())
            ->postJson('/api/v1/inventory/suppliers', ['account_id' => $account->id, 'code' => 'JFP', 'name' => 'Just_ForPrint'])
            ->assertUnauthorized();
    }
}
