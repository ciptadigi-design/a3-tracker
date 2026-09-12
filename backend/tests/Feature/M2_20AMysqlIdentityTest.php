<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\PlatformUserPrivilege;
use App\Models\User;
use App\Services\MemberLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class M2_20AMysqlIdentityTest extends TestCase
{
    use RefreshDatabase;

    public static function transitions(): array
    {
        return ['demote' => [['role' => 'operator']], 'suspend' => [['status' => 'suspended']], 'revoke' => [['status' => 'revoked']]];
    }

    #[DataProvider('transitions')]
    public function test_account_lock_serializes_competing_last_owner_transitions(array $change): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires independent MySQL/InnoDB connections.');
        }
        $a = Account::create(['code' => 'IDENTITY-RACE', 'name' => 'Synthetic race', 'status' => 'active']);
        $actor = User::factory()->create(['status' => 'active']);
        PlatformUserPrivilege::create(['user_id' => $actor->id, 'role' => 'superuser', 'is_active' => true]);
        $owners = [];
        $users = [];
        foreach ([1, 2] as $n) {
            $users[] = $u = User::factory()->create(['status' => 'active']);
            $owners[] = $a->memberships()->create(['user_id' => $u->id, 'role' => 'owner', 'status' => 'active']);
        }
        DB::connection()->commit();
        $process = null;
        try {
            DB::beginTransaction();
            Account::whereKey($a->id)->lockForUpdate()->firstOrFail();
            $process = new Process([PHP_BINARY, base_path('tests/Support/transition_owner.php'), $actor->id, $a->id, $owners[1]->id, json_encode($change)], base_path(), ['APP_ENV' => 'testing']);
            $process->setTimeout(15)->start();
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'READY')), $process->getErrorOutput());
            // Hold the first transaction while the independent request reaches the lock.
            usleep(250000);
            $this->assertTrue($process->isRunning(), 'Competing transition bypassed the account lock: '.$process->getOutput());
            app(MemberLifecycle::class)->update($actor, $a, $owners[0]->id, $change);
            DB::commit();
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertStringContainsString('"status":409', $process->getOutput());
            $this->assertSame(1, $a->memberships()->where('role', 'owner')->where('status', 'active')->count());
        } finally {
            if ($process?->isRunning()) {
                $process->stop();
            }
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::table('governance_audit_logs')->where('account_id', $a->id)->delete();
            DB::table('account_memberships')->where('account_id', $a->id)->delete();
            $a->delete();
            $actor->platformPrivilege()->delete();
            foreach ([$actor, ...$users] as $u) {
                $u->delete();
            }
            DB::beginTransaction();
        }
    }

    public function test_mysql_collation_collision_is_a_neutral_conflict(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL case-insensitive uniqueness contract.');
        }
        $a = Account::create(['code' => 'COLLATION', 'name' => 'Synthetic collation', 'status' => 'active']);
        $b = $a->branches()->create(['code' => 'MAIN', 'name' => 'Main', 'is_active' => true]);
        $actor = User::factory()->create(['status' => 'active']);
        $a->memberships()->create(['user_id' => $actor->id, 'role' => 'owner', 'status' => 'active']);
        $foreign = User::factory()->create(['email' => 'Résumé@example.test', 'username' => 'FOREIGN.USER', 'status' => 'active']);
        $this->actingAs($actor)->postJson('/api/v1/accounts/'.$a->id.'/members', ['name' => 'Fresh', 'email' => 'resume@example.test', 'username' => 'new.user', 'password' => 'initial-password', 'role' => 'operator', 'branch_ids' => [$b->id]])->assertConflict();
        $this->assertFalse($a->memberships()->where('user_id', $foreign->id)->exists());
    }
}
