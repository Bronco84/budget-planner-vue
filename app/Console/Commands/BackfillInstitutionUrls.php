<?php

namespace App\Console\Commands;

use App\Models\PlaidConnection;
use App\Services\PlaidService;
use Illuminate\Console\Command;

class BackfillInstitutionUrls extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plaid:backfill-institution-urls {--dry-run : Run without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill institution URLs for existing Plaid connections';

    /**
     * Execute the console command.
     */
    public function handle(PlaidService $plaidService)
    {
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->info('Running in dry-run mode. No changes will be made.');
        }

        // Get all connections that have an institution_id but no institution_url
        $connections = PlaidConnection::whereNotNull('institution_id')
            ->where(function ($query) {
                $query->whereNull('institution_url')
                      ->orWhere('institution_url', '');
            })
            ->get();

        if ($connections->isEmpty()) {
            $this->info('No connections found that need URL backfilling.');
            return Command::SUCCESS;
        }

        $this->info("Found {$connections->count()} connection(s) to process.");

        $progressBar = $this->output->createProgressBar($connections->count());
        $progressBar->start();

        $updated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($connections as $connection) {
            try {
                // Fetch institution details from Plaid
                $institutionDetails = $plaidService->getInstitutionDetails($connection->institution_id);
                
                if ($institutionDetails && isset($institutionDetails['url']) && !empty($institutionDetails['url'])) {
                    if (!$dryRun) {
                        $connection->update([
                            'institution_url' => $institutionDetails['url']
                        ]);
                    }
                    
                    $this->newLine();
                    $this->line("✓ Updated {$connection->institution_name}: {$institutionDetails['url']}");
                    $updated++;
                } else {
                    $this->newLine();
                    $this->line("⊘ No URL available for {$connection->institution_name}");
                    $skipped++;
                }
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("✗ Failed to update {$connection->institution_name}: {$e->getMessage()}");
                $failed++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Summary
        $this->info('Backfill complete!');
        $this->table(
            ['Status', 'Count'],
            [
                ['Updated', $updated],
                ['Skipped (no URL)', $skipped],
                ['Failed', $failed],
                ['Total', $connections->count()],
            ]
        );

        if ($dryRun) {
            $this->newLine();
            $this->warn('This was a dry run. Run without --dry-run to apply changes.');
        }

        return Command::SUCCESS;
    }
}
