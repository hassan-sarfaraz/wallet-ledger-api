<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('ledger:explain {user_id=seed_0}')]
#[Description('Print EXPLAIN plans for the credit, debit and balance statements')]
class ExplainLedger extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $userId = (string) $this->argument('user_id');
        $queries = [
            ['credit/create', 'INSERT INTO wallets (user_id, balance) VALUES (?, 0) ON DUPLICATE KEY UPDATE user_id = wallets.user_id', [$userId]],
            ['credit/debit lock', 'SELECT balance FROM wallets WHERE user_id = ? LIMIT 1 FOR UPDATE', [$userId]],
            ['credit/debit update', 'UPDATE wallets SET balance = ? WHERE user_id = ?', [100, $userId]],
            ['credit/debit append', 'INSERT INTO ledger (user_id, type, amount) VALUES (?, ?, ?)', [$userId, 'credit', 100]],
            ['get_balance', 'SELECT balance FROM wallets WHERE user_id = ? LIMIT 1', [$userId]],
        ];

        foreach ($queries as [$label, $sql, $bindings]) {
            $this->line($label.': '.$sql);
            $this->line(json_encode(DB::select('EXPLAIN '.$sql, $bindings), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
