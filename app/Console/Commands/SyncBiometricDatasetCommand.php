<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Database\Seeders\LoopsBiometricDatasetSeeder;

class SyncBiometricDatasetCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hr:sync-biometric';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed and synchronize the official Loops Digital 50-employee biometric dataset (1 Sep - 14 Oct 2026)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting biometric dataset synchronization...');

        $seeder = new LoopsBiometricDatasetSeeder();
        $seeder->setCommand($this);
        $seeder->run();

        $this->info('✓ Biometric dataset synchronized successfully.');
        $this->table(
            ['Metric', 'Count / Value'],
            [
                ['Workforce', '50 Employees'],
                ['Departments', '8 Active Operational Departments'],
                ['Attendance Range', '1 Sep 2026 – 14 Oct 2026'],
                ['Total Attendances', '1,586 Logs'],
                ['Approved Leave Requests', '58 Events (55 Days)'],
            ]
        );

        return Command::SUCCESS;
    }
}
