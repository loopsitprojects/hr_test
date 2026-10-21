<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            LoopsBiometricDatasetSeeder::class,
        ]);

        $this->command?->info('Crew HR core database & Loops Digital biometric dataset seeded successfully.');
    }
}
