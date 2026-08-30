<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase; use Illuminate\Support\Facades\DB; use Tests\TestCase;
class TransactionFoundationTest extends TestCase { use RefreshDatabase; public function test_transaction_rolls_back(): void { try { DB::transaction(function(){ DB::table('accounts')->insert(['id'=>(string)\Illuminate\Support\Str::uuid(),'code'=>'ROLLBACK','name'=>'Rollback']); throw new \RuntimeException('rollback'); }); } catch (\RuntimeException) {} $this->assertDatabaseMissing('accounts',['code'=>'ROLLBACK']); }
 public function test_foundation_tables_use_innodb_when_mysql_is_configured(): void { if (DB::getDriverName() !== 'mysql') { $this->markTestSkipped('MySQL integration assertion runs in target CI.'); } $engine=DB::selectOne("select engine from information_schema.tables where table_schema=database() and table_name='accounts'"); $this->assertSame('InnoDB',$engine->engine); }
}
