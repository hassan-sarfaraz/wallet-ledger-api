<?php

namespace Tests\Feature;

use App\Services\WalletLedger;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LedgerSeedTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_seed_is_skewed_and_reconciles_with_nonnegative_history(): void
    {
        $this->artisan('ledger:seed', ['--rows' => 10000, '--users' => 100, '--batch' => 333])->assertSuccessful();

        $this->assertDatabaseCount('ledger', 10000);
        $this->assertDatabaseCount('wallets', 100);
        $this->assertSame(100, DB::table('ledger')->where('user_id', 'seed_0')->count());
        $this->assertSame(3000, (int) DB::table('wallets')->where('user_id', 'seed_0')->value('balance'));
        $this->artisan('ledger:reconcile', ['--history' => true])->assertSuccessful();
        $this->artisan('ledger:seed', ['--rows' => 10000, '--users' => 100])->assertFailed();
    }

    public function test_reconciliation_fails_for_a_balance_disagreeing_with_the_ledger(): void
    {
        app(WalletLedger::class)->credit('mismatch', 100);
        DB::table('wallets')->where('user_id', 'mismatch')->update(['balance' => 99]);

        $this->artisan('ledger:reconcile')->assertFailed();
    }
}
