<?php

namespace App\Console\Commands;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * The database cache store deletes an expired row only when that exact key is
 * read again. Keys that are never read again (such as the versioned projection
 * keys BudgetService orphans on every invalidation) would stay forever, so this
 * removes every expired row. Other stores expire entries themselves.
 */
class PruneExpiredCacheEntries extends Command
{
    protected $signature = 'cache:prune-expired
                            {store? : The cache store to prune (defaults to the default store)}';

    protected $description = 'Delete expired rows from a database cache store';

    private const CHUNK = 1000;

    public function handle(): int
    {
        $name = $this->argument('store') ?? config('cache.default');
        $store = Cache::store($name)->getStore();

        if (! $store instanceof DatabaseStore) {
            $this->info("The [{$name}] cache store is not a database store; nothing to prune.");

            return self::SUCCESS;
        }

        $table = config("cache.stores.{$name}.table", 'cache');
        $now = now()->getTimestamp();
        $deleted = 0;

        do {
            $batch = $store->getConnection()->table($table)
                ->where('expiration', '<=', $now)
                ->limit(self::CHUNK)
                ->delete();
            $deleted += $batch;
        } while ($batch === self::CHUNK);

        $this->info("Pruned {$deleted} expired cache entries from [{$table}].");

        return self::SUCCESS;
    }
}
