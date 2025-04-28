<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Console\Commands\IdentifyRecurringTransactions;
use App\Console\Commands\IdentifyAllRecurringTransactions;
use App\Console\Commands\GenerateRecurringTransactions;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        IdentifyRecurringTransactions::class,
        IdentifyAllRecurringTransactions::class,
        GenerateRecurringTransactions::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();
        
        // Identify recurring transactions weekly
        $schedule->command('transactions:identify-all-recurring --months=3 --min-occurrences=2')
            ->weekly()
            ->sundays()
            ->at('02:00')
            ->appendOutputTo(storage_path('logs/recurring-transactions-identify.log'));
            
        // Generate upcoming recurring transactions daily
        $schedule->command('transactions:generate-recurring --days=60')
            ->daily()
            ->at('03:00')
            ->appendOutputTo(storage_path('logs/recurring-transactions-generate.log'));
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
} 