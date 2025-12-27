<?php

namespace App\Console\Commands;

use App\Models\PlaidConnection;
use App\Services\PlaidService;
use Illuminate\Console\Command;

class BackfillPlaidInstitutionLogos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plaid:backfill-logos 
                            {--force : Overwrite existing logos}
                            {--dry-run : Show what would be updated without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill institution logos for existing Plaid connections';

    /**
     * Execute the console command.
     */
    public function handle(PlaidService $plaidService): int
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        // Find all connections that need logos
        $query = PlaidConnection::query();

        if (!$force) {
            $query->where(function ($q) {
                $q->whereNull('institution_logo')
                  ->orWhere('institution_logo', '');
            });
        }

        $connections = $query->get();

        if ($connections->isEmpty()) {
            $this->info('No connections need logo backfill.');
            return Command::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d connection(s) to backfill...', 
            $dryRun ? 'Would process' : 'Processing',
            $connections->count()
        ));

        $successCount = 0;
        $failCount = 0;

        $bar = $this->output->createProgressBar($connections->count());
        $bar->start();

        foreach ($connections as $connection) {
            $bar->advance();

            if ($dryRun) {
                $this->line('');
                $this->info("Would fetch logo for: {$connection->institution_name}");
                $successCount++;
                continue;
            }

            try {
                $details = null;
                $institutionId = $connection->institution_id;

                // If we have an institution ID, use it directly
                if (!empty($institutionId)) {
                    $details = $plaidService->getInstitutionDetails($institutionId);
                }

                // If no ID or no details, try searching by name
                if (!$details && !empty($connection->institution_name)) {
                    $this->line('');
                    $this->info("Searching for institution: {$connection->institution_name}");
                    
                    $searchResult = $plaidService->searchInstitutions($connection->institution_name);
                    
                    if ($searchResult) {
                        $details = $searchResult;
                        
                        // Also update the institution_id if we found it
                        if (!empty($searchResult['institution_id']) && empty($connection->institution_id)) {
                            $connection->institution_id = $searchResult['institution_id'];
                        }
                    }
                }

                if ($details && !empty($details['logo'])) {
                    $connection->institution_logo = $details['logo'];
                    $connection->save();
                    $successCount++;
                    $this->line('');
                    $this->info("✓ Updated logo for: {$connection->institution_name}");
                } else {
                    $this->line('');
                    $this->warn("No logo available for: {$connection->institution_name}");
                    $failCount++;
                }

                // Small delay to avoid rate limiting
                usleep(300000); // 300ms

            } catch (\Exception $e) {
                $this->line('');
                $this->error("Failed to fetch logo for {$connection->institution_name}: {$e->getMessage()}");
                $failCount++;
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Backfill complete: {$successCount} succeeded, {$failCount} failed.");

        return Command::SUCCESS;
    }
}
