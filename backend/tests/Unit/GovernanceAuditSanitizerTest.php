<?php

namespace Tests\Unit;

use App\Services\GovernanceAudit;
use PHPUnit\Framework\TestCase;

class GovernanceAuditSanitizerTest extends TestCase
{
    public function test_nested_sensitive_keys_removed_without_harmless_substring_collisions(): void
    {
        $keys = ['password', 'password_confirmation', 'password_hash', 'remember_token', 'session_id', 'session_version', 'token', 'access_token', 'refresh_token', 'authorization', 'api_key', 'secret', 'DB_PASSWORD', 'private-key', 'supabase_key', 'ssh_secret', 'csrf_token', 'sessionToken', 'temporary_password'];
        $secrets = array_fill_keys($keys, 'sensitive');
        $safe = ['token_count' => 4, 'secretariat' => 'office', 'password_policy_enabled' => true, 'name' => 'Supplier'];
        $audit = new GovernanceAudit;
        $this->assertSame($safe + ['nested' => [$safe]], $audit->sanitize($secrets + $safe + ['nested' => [$secrets + $safe]]));
        $this->assertSame(['object' => '[omitted]'], $audit->sanitize(['object' => (object) $secrets]));
    }
}
