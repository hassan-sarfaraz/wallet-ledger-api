<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (Artisan::call('ledger:seed', ['--no-interaction' => true]) !== 0) {
            throw new RuntimeException(Artisan::output());
        }

        $this->command?->info(Artisan::output());
    }
}
