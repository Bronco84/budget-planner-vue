<?php

namespace App\Console\Commands;

use App\Models\PlaidConnection;
use App\Services\PlaidService;
use Illuminate\Console\Command;

class BackfillPlaidInstitutionData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plaid:backfill-institution-data 
                            {--force : Overwrite existing logo and URL data}
                            {--dry-run : Show what would be updated without making changes}
                            {--connection-id= : Only process a specific connection ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill institution logo and URL for Plaid connections from Plaid API';

    /**
     * Execute the console command.
     */
    public function handle(PlaidService $plaidService): int
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        $connectionId = $this->option('connection-id');

        // Build query
        $query = PlaidConnection::query();

        if ($connectionId) {
            $query->where('id', $connectionId);
        }

        // If not forcing, only get connections missing data
        if (!$force) {
            $query->where(function ($q) {
                $q->whereNull('institution_logo')
                  ->orWhere('institution_logo', '')
                  ->orWhereNull('institution_url')
                  ->orWhere('institution_url', '');
            });
        }

        $connections = $query->get();

        if ($connections->isEmpty()) {
            $this->info('No connections need institution data backfill.');
            return Command::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d connection(s)...',
            $dryRun ? 'Would process' : 'Processing',
            $connections->count()
        ));
        $this->newLine();

        $updatedCount = 0;
        $skippedCount = 0;
        $failedCount = 0;

        foreach ($connections as $index => $connection) {
            $num = $index + 1;
            $total = $connections->count();
            
            $this->line("[{$num}/{$total}] <info>{$connection->institution_name}</info>");

            try {
                $details = null;
                $institutionId = $connection->institution_id;

                // Step 1: Get institution details
                if (!empty($institutionId)) {
                    $this->line("  → Has institution_id: {$institutionId}");
                    $details = $plaidService->getInstitutionDetails($institutionId);
                } else {
                    $this->line("  → No institution_id, searching by name...");
                    
                    // Search by name to find institution_id
                    $searchResult = $plaidService->searchInstitutions($connection->institution_name);
                    
                    if ($searchResult && !empty($searchResult['institution_id'])) {
                        $institutionId = $searchResult['institution_id'];
                        $this->line("  → Found: <comment>{$institutionId}</comment>");
                        
                        // Now get full details including URL
                        $details = $plaidService->getInstitutionDetails($institutionId);
                        
                        // Update institution_id
                        if (!$dryRun) {
                            $connection->institution_id = $institutionId;
                        }
                    } else {
                        $this->warn("  → Could not find institution by name");
                        $failedCount++;
                        $this->newLine();
                        continue;
                    }
                }

                if (!$details) {
                    $this->warn("  → Failed to fetch institution details from Plaid");
                    $failedCount++;
                    $this->newLine();
                    continue;
                }

                // Step 2: Check what needs updating
                $needsUpdate = false;
                $updates = [];

                // Check logo
                $hasLogo = !empty($connection->institution_logo);
                $newLogo = $details['logo'] ?? null;
                
                if ($newLogo && ($force || !$hasLogo)) {
                    $logoSize = strlen($newLogo);
                    $this->line("  → Logo: <info>✓</info> ({$logoSize} bytes)");
                    $updates['institution_logo'] = $newLogo;
                    $needsUpdate = true;
                } elseif ($hasLogo && !$force) {
                    $this->line("  → Logo: Already set (skipping)");
                } elseif (!$newLogo) {
                    $this->line("  → Logo: <comment>Not available from Plaid</comment>");
                }

                // Check URL
                $hasUrl = !empty($connection->institution_url);
                $newUrl = $details['url'] ?? null;
                
                if ($newUrl && ($force || !$hasUrl)) {
                    $this->line("  → URL: <info>{$newUrl}</info>");
                    $updates['institution_url'] = $newUrl;
                    $needsUpdate = true;
                } elseif ($hasUrl && !$force) {
                    $this->line("  → URL: Already set (skipping)");
                } elseif (!$newUrl) {
                    $this->line("  → URL: <comment>Not available from Plaid</comment>");
                }

                // Check primary_color (bonus)
                $hasPrimaryColor = !empty($connection->primary_color);
                $newPrimaryColor = $details['primary_color'] ?? null;
                
                if ($newPrimaryColor && ($force || !$hasPrimaryColor)) {
                    $this->line("  → Primary Color: <info>{$newPrimaryColor}</info>");
                    // Note: primary_color column doesn't exist yet, so we won't save it
                    // $updates['primary_color'] = $newPrimaryColor;
                }

                // Step 3: Save updates
                if ($needsUpdate) {
                    if (!$dryRun) {
                        // Also save institution_id if it was found via search
                        if (!empty($institutionId) && empty($connection->institution_id)) {
                            $updates['institution_id'] = $institutionId;
                        }
                        
                        $connection->update($updates);
                        $this->line("  → <info>Updated!</info>");
                    } else {
                        $this->line("  → <comment>Would update (dry-run)</comment>");
                    }
                    $updatedCount++;
                } else {
                    $this->line("  → No updates needed");
                    $skippedCount++;
                }

                $this->newLine();

                // Small delay to avoid rate limiting
                usleep(300000); // 300ms

            } catch (\Exception $e) {
                $this->error("  → Error: {$e->getMessage()}");
                $failedCount++;
                $this->newLine();
            }
        }

        $this->newLine();
        $this->info("Complete: {$updatedCount} updated, {$skippedCount} skipped, {$failedCount} failed.");

        return Command::SUCCESS;
    }
}
