<?php

namespace App\Console\Commands;

use App\Services\FileService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Retries removing files nothing is attached to: those left when an S3 delete
 * failed while detaching a file or deleting a transaction, budget or account.
 */
class CleanupOrphanedFiles extends Command
{
    protected $signature = 'files:cleanup-orphans
                            {--hours=24 : Only files unchanged for at least this many hours}';

    protected $description = 'Delete files with no attachments, and their unused S3 objects';

    public function handle(FileService $files): int
    {
        $hours = max(0, (int) $this->option('hours'));

        $summary = $files->cleanupOrphanedFiles(
            untouchedSince: $hours > 0 ? now()->subHours($hours) : null,
        );

        $this->info("Orphaned files: {$summary['total_orphaned']}, deleted: {$summary['successfully_deleted']}, failed: {$summary['failed_deletions']}.");

        if ($summary['failed_deletions'] > 0) {
            Log::warning('Some orphaned files could not be deleted; they will be retried', [
                'failed' => $summary['failed_deletions'],
                'errors' => array_slice($summary['errors'], 0, 20),
            ]);
        }

        return self::SUCCESS;
    }
}
