<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * M2.17.5.3: /api/v1/version reported git_sha="unknown" in Production despite a
 * correctly deployed release, because VersionController called env('APP_GIT_SHA')
 * directly - Laravel skips loading .env entirely once `php artisan config:cache`
 * has run, so a raw env() call outside a config/*.php file returns null from
 * that point on. The fix reads config('release.git_sha') instead, which is
 * resolved (and frozen) at config-cache time via config/release.php.
 *
 * Config::set() is used here rather than actually shelling out to
 * `artisan config:cache` (which would write a real cache file as a test side
 * effect) - it reproduces the exact property that matters: once config is
 * resolved, the controller must depend on config(), not on live env() state.
 */
class M2_17_5_3_VersionReleaseIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function assertShaResponse(string $expected): void
    {
        $this->getJson('/api/v1/version')->assertOk()->assertJsonPath('data.git_sha', $expected);
    }

    public function test_version_reads_git_sha_from_config_not_from_live_env(): void
    {
        $sha = str_repeat('a', 40);
        Config::set('release.git_sha', $sha);
        // Simulate the exact regression: config already resolved, but a raw env()
        // call outside config/*.php would now return null once config:cache has
        // run - putenv('') here proves the controller no longer depends on that.
        putenv('APP_GIT_SHA');
        unset($_ENV['APP_GIT_SHA'], $_SERVER['APP_GIT_SHA']);
        $this->assertShaResponse($sha);
    }

    public function test_version_reflects_config_value_even_when_live_env_disagrees(): void
    {
        $configured = str_repeat('b', 40);
        Config::set('release.git_sha', $configured);
        putenv('APP_GIT_SHA='.str_repeat('c', 40));
        // The deployed release's frozen config identity must win - never a live,
        // possibly-stale environment variable read at request time.
        $this->assertShaResponse($configured);
        putenv('APP_GIT_SHA');
    }

    public function test_exact_40_char_sha_is_returned_unchanged(): void
    {
        $sha = '0123456789abcdef0123456789abcdef01234567';
        Config::set('release.git_sha', $sha);
        $this->assertShaResponse($sha);
    }

    public function test_missing_config_falls_back_to_unknown_not_an_exception(): void
    {
        Config::set('release.git_sha', null);
        $this->assertShaResponse('unknown');
    }

    public function test_empty_string_config_falls_back_to_unknown(): void
    {
        Config::set('release.git_sha', '');
        $this->assertShaResponse('unknown');
    }

    public function test_abbreviated_sha_is_rejected_as_not_a_valid_release_identity(): void
    {
        Config::set('release.git_sha', 'dcd9717');
        $this->assertShaResponse('unknown');
    }

    public function test_a_non_hex_or_wrong_length_value_never_passes_through_as_a_false_identity(): void
    {
        Config::set('release.git_sha', 'not-a-real-sha-at-all');
        $this->assertShaResponse('unknown');
        Config::set('release.git_sha', str_repeat('a', 41));
        $this->assertShaResponse('unknown');
        Config::set('release.git_sha', str_repeat('g', 40));
        $this->assertShaResponse('unknown');
    }

    public function test_response_exposes_no_sensitive_environment_data(): void
    {
        Config::set('release.git_sha', str_repeat('d', 40));
        $data = $this->getJson('/api/v1/version')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['application', 'backend', 'git_sha', 'schema_batch'], array_keys($data));
        $encoded = strtolower(json_encode($data));
        foreach (['app_key', 'db_password', 'db_username', 'secret', 'token'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function test_existing_version_response_shape_is_unchanged(): void
    {
        $this->getJson('/api/v1/version')->assertOk()->assertJsonStructure(['data' => ['application', 'backend', 'git_sha', 'schema_batch']]);
    }

    public function test_version_endpoint_remains_unauthenticated(): void
    {
        $this->getJson('/api/v1/version')->assertOk();
    }
}
