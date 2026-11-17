<?php

namespace App\Services;

use App\Exceptions\WalletException;
use Illuminate\Support\Facades\DB;

class WalletLedger
{
    public function balance(string $userId): int
    {
        $balance = DB::table('wallets')->where('user_id', $userId)->value('balance');

        if ($balance === null) {
            throw new WalletException('user_not_found', 'User has never been credited.', 404);
        }

        return (int) $balance;
    }

    public function credit(string $userId, int $amount): int
    {
        return DB::transaction(function () use ($userId, $amount): int {
            DB::statement('INSERT INTO wallets (user_id, balance) VALUES (?, 0) ON DUPLICATE KEY UPDATE user_id = wallets.user_id', [$userId]);

            return $this->writeLocked($userId, $amount, 'credit');
        }, attempts: 100);
    }

    public function debit(string $userId, int $amount): int
    {
        return DB::transaction(fn (): int => $this->writeLocked($userId, $amount, 'debit'), attempts: 100);
    }

    private function writeLocked(string $userId, int $amount, string $type): int
    {
        $wallet = DB::table('wallets')->where('user_id', $userId)->lockForUpdate()->first(['balance']);

        if ($wallet === null) {
            throw new WalletException('user_not_found', 'User has never been credited.', 404);
        }

        if ($type === 'debit' && (int) $wallet->balance < $amount) {
            throw new WalletException('insufficient_funds', 'Balance is insufficient for this debit.', 409);
        }

        $balance = (int) $wallet->balance + ($type === 'credit' ? $amount : -$amount);
        DB::table('wallets')->where('user_id', $userId)->update(['balance' => $balance]);
        DB::table('ledger')->insert(['user_id' => $userId, 'type' => $type, 'amount' => $amount]);

        return $balance;
    }
}
