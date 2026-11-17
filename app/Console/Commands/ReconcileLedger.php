<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('ledger:reconcile {--history : Also verify every historical running balance}')]
#[Description('Verify stored balances against the immutable ledger using SQL')]
class ReconcileLedger extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $mismatches = DB::selectOne("SELECT COUNT(*) AS total FROM wallets w LEFT JOIN (
            SELECT user_id, SUM(IF(type = 'credit', CAST(amount AS SIGNED), -CAST(amount AS SIGNED))) balance
            FROM ledger FORCE INDEX (PRIMARY) GROUP BY user_id
        ) l ON l.user_id = w.user_id WHERE w.balance <> COALESCE(l.balance, 0) OR w.balance < 0");

        if ((int) $mismatches->total !== 0) {
            $this->error("Balance mismatches: {$mismatches->total}");

            return self::FAILURE;
        }

        if ($this->option('history')) {
            $negative = DB::selectOne("SELECT user_id, id FROM (
                SELECT user_id, id, SUM(IF(type = 'credit', CAST(amount AS SIGNED), -CAST(amount AS SIGNED)))
                OVER (PARTITION BY user_id ORDER BY id ROWS UNBOUNDED PRECEDING) AS running_balance FROM ledger
            ) history WHERE running_balance < 0 LIMIT 1");

            if ($negative !== null) {
                $this->error("Negative historical balance for {$negative->user_id} at row {$negative->id}");

                return self::FAILURE;
            }
        }

        $this->info('All balances match the ledger; no negative balances.');

        return self::SUCCESS;
    }
}
