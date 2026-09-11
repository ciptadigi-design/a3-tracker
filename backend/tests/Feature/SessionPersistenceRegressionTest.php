<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Regression guard for a real (non-actingAs) cookie-based login: the stock
 * Laravel `sessions.user_id` column is an unsigned bigint, but every User
 * here has a UUID string id. Under MySQL's strict SQL mode (forced on by
 * config/database.php's 'strict' => true), DatabaseSessionHandler::write()
 * threw on that type mismatch and silently fell back to a no-op UPDATE, so a
 * freshly authenticated session was never actually persisted - the very next
 * request saw no session at all. actingAs()-based tests never exercise the
 * real session-write path, and SQLite's loose typing masked it there too, so
 * this only ever showed up via a genuine browser login against MySQL. This
 * test only means anything on MySQL (see the skip below); it intentionally
 * inspects the sessions table directly rather than relying on cookie replay,
 * which Laravel's test client does not do automatically across requests.
 */
class SessionPersistenceRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_real_login_persists_the_authenticated_session_row_on_mysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL-specific session persistence regression guard.');
        }
        config(['session.driver' => 'database']);

        $user = User::create(['name' => 'Session Regression', 'email' => 'session-regression@example.com', 'username' => 'session_regression', 'password' => Hash::make('password123'), 'status' => 'active']);

        $response = $this->postJson('/api/v1/auth/login', ['identifier' => 'session_regression', 'password' => 'password123'])->assertOk();

        $cookieName = config('session.cookie');
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === $cookieName);
        $this->assertNotNull($cookie, "Login response did not set the {$cookieName} session cookie.");

        $sessionId = explode('|', Crypt::decrypt($cookie->getValue(), false), 2)[1];
        $row = DB::table('sessions')->where('id', $sessionId)->first();

        $this->assertNotNull($row, 'The authenticated session was never persisted to the sessions table.');
        $payload = base64_decode($row->payload);
        $this->assertStringContainsString('login_web_', $payload, 'Persisted session payload does not carry the authenticated guard key.');
        $this->assertStringContainsString($user->id, $payload, 'Persisted session payload does not reference the logged-in user.');
    }
}
