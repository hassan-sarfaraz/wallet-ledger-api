<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('ledger:seed {--rows=10000000} {--users=100000} {--batch=50000}')]
#[Description('Load a deterministic, skewed ledger into an empty database')]
class SeedLedger extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rows = filter_var($this->option('rows'), FILTER_VALIDATE_INT);
        $users = filter_var($this->option('users'), FILTER_VALIDATE_INT);
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);

        if ($users < 20 || $users > 100000 || $rows < $users * 2 || $batch < 1 || $batch > 100000) {
            $this->error('Require 20 <= users <= 100000, rows >= 2 * users, and 1 <= batch <= 100000.');

            return self::FAILURE;
        }

        if (DB::table('wallets')->exists() || DB::table('ledger')->exists()) {
            $this->error('Seed requires empty wallets and ledger tables. Existing data is never deleted.');

            return self::FAILURE;
        }

        $started = microtime(true);
        $hotUsers = 10;
        $hotPerUser = max(1, intdiv($rows, 100));
        $hotRows = $hotUsers * $hotPerUser;
        $regularUsers = $users - $hotUsers;
        $db = DB::connection();
        $db->statement('CREATE TEMPORARY TABLE seed_numbers (n INT PRIMARY KEY)');
        $digits = '(SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
        $db->statement("INSERT INTO seed_numbers SELECT a.d + b.d*10 + c.d*100 + d.d*1000 + e.d*10000 FROM {$digits} a CROSS JOIN {$digits} b CROSS JOIN {$digits} c CROSS JOIN {$digits} d CROSS JOIN {$digits} e");
        $db->statement("INSERT INTO wallets (user_id, balance) SELECT CONCAT('seed_', n), 0 FROM seed_numbers WHERE n < ? ORDER BY n", [$users]);

        try {
            for ($offset = 0; $offset < $rows; $offset += $batch) {
                $count = min($batch, $rows - $offset);
                $db->transaction(function () use ($db, $offset, $count, $hotRows, $hotPerUser, $hotUsers, $regularUsers): void {
                    $firstId = (int) ($db->table('ledger')->max('id') ?? 0);
                    $db->insert("INSERT INTO ledger (user_id, type, amount)
                        SELECT CONCAT('seed_', IF(g < ?, FLOOR(g / ?), ? + MOD(g - ?, ?))),
                            IF(MOD(IF(g < ?, MOD(g, ?), FLOOR((g - ?) / ?)), 2) = 0, 'credit', 'debit'),
                            IF(MOD(IF(g < ?, MOD(g, ?), FLOOR((g - ?) / ?)), 2) = 0, 100, 40)
                        FROM (SELECT n + ? AS g FROM seed_numbers WHERE n < ?) numbers ORDER BY g",
                        [$hotRows, $hotPerUser, $hotUsers, $hotRows, $regularUsers,
                            $hotRows, $hotPerUser, $hotRows, $regularUsers,
                            $hotRows, $hotPerUser, $hotRows, $regularUsers, $offset, $count]);
                    $lastId = (int) $db->table('ledger')->max('id');
                    $db->update("UPDATE wallets w JOIN (
                        SELECT user_id, SUM(IF(type = 'credit', CAST(amount AS SIGNED), -CAST(amount AS SIGNED))) delta
                        FROM ledger WHERE id > ? AND id <= ? GROUP BY user_id
                    ) changes ON changes.user_id = w.user_id SET w.balance = w.balance + changes.delta", [$firstId, $lastId]);
                }, attempts: 10);

                if (($offset + $count) % 1000000 === 0 || $offset + $count === $rows) {
                    $this->info(sprintf('%d rows loaded in %.2f seconds', $offset + $count, microtime(true) - $started));
                }
            }
        } finally {
            $db->statement('DROP TEMPORARY TABLE seed_numbers');
        }

        $this->info(sprintf('Complete: %d rows, %d users, %d rows per hot user, %.2f seconds.', $rows, $users, $hotPerUser, microtime(true) - $started));

        return self::SUCCESS;
    }
}
