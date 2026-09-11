<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Laravel's stock sessions table declares user_id as an unsigned bigint, but
     * every User in this app has a UUID string id. Under MySQL's default strict
     * SQL mode (forced on by config/database.php's 'strict' => true), writing a
     * UUID into that column throws a truncation error on INSERT; the session
     * write silently falls back to a no-op UPDATE, so a freshly authenticated
     * session is never actually persisted - the very next request finds no
     * session and is treated as anonymous. SQLite's loose type affinity had
     * been masking this everywhere the automated suite runs (and actingAs()
     * bypasses the real session-write path entirely), which is why this went
     * unnoticed until a real cookie-based browser login was exercised against
     * MySQL. This column is metadata only (never used to authorize a query),
     * so widening it to a UUID-compatible string is safe.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE sessions MODIFY user_id VARCHAR(36) NULL');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('DELETE FROM sessions WHERE user_id IS NOT NULL');
            DB::statement('ALTER TABLE sessions MODIFY user_id BIGINT UNSIGNED NULL');
        }
    }
};
