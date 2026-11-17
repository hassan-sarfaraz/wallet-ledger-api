<?php

namespace Tests\Feature;

use App\Services\WalletLedger;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WalletRetryTest extends TestCase
{
    use DatabaseMigrations;

    public function test_an_uncommitted_wallet_write_does_not_block_other_users_or_expose_dirty_balances(): void
    {
        $ledger = app(WalletLedger::class);
        $ledger->credit('locked', 100);
        $ledger->credit('independent', 100);
        config(['database.connections.locker' => config('database.connections.mysql')]);
        $locker = DB::connection('locker');
        $locker->beginTransaction();

        try {
            $locker->table('wallets')->where('user_id', 'locked')->update(['balance' => 1]);

            $this->assertSame(100, $ledger->balance('locked'));
            $this->assertSame(101, $ledger->credit('independent', 1));
            $this->assertDatabaseHas('ledger', ['user_id' => 'independent', 'type' => 'credit', 'amount' => 1]);
        } finally {
            $locker->rollBack();
            DB::purge('locker');
        }
    }

    public function test_retries_a_deadlock_without_applying_a_credit_twice(): void
    {
        $ledger = app(WalletLedger::class);
        $ledger->credit('retry', 100);
        $failed = false;
        DB::listen(function (QueryExecuted $query) use (&$failed): void {
            if (! $failed && str_starts_with($query->sql, 'update `wallets`')) {
                $failed = true;
                throw new \PDOException('Deadlock found when trying to get lock', 40001);
            }
        });

        $balance = $ledger->credit('retry', 50);

        $this->assertSame(150, $balance);
        $this->assertDatabaseHas('wallets', ['user_id' => 'retry', 'balance' => 150]);
        $this->assertDatabaseCount('ledger', 2);
    }
}
