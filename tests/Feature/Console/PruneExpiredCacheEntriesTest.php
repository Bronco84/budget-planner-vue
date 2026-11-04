<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * PR-069. The database cache store only deletes an expired row when that key is
 * read, so keys never read again (replaced projection cache versions) must be
 * swept by the scheduled cache:prune-expired.
 */

function prunedCacheKeys(): array
{
    $prefix = Cache::store('database')->getPrefix();

    return DB::table('cache')->pluck('key')
        ->map(fn (string $key) => substr($key, strlen($prefix)))
        ->sort()->values()->all();
}

test('cache:prune-expired removes expired rows from the database cache and keeps live ones', function () {
    config(['cache.default' => 'database']);
    $cache = Cache::store('database');
    $cache->put('account:1:projections:old-version:2026-01-01:2026-12-31', ['stale'], 60);
    $cache->put('account:1:projections:new-version:2026-01-01:2026-12-31', ['live'], 3600);
    $cache->forever('account:1:projection-version', 'new-version');

    $this->travel(5)->minutes();

    $this->artisan('cache:prune-expired')->assertSuccessful();

    expect(prunedCacheKeys())->toBe([
        'account:1:projection-version',
        'account:1:projections:new-version:2026-01-01:2026-12-31',
    ])->and($cache->get('account:1:projections:new-version:2026-01-01:2026-12-31'))->toBe(['live']);
});

test('cache:prune-expired removes more expired rows than one batch', function () {
    $now = now()->getTimestamp();
    DB::table('cache')->insert(collect(range(1, 1001))
        ->map(fn (int $i) => ['key' => "expired-{$i}", 'value' => 's:1:"x";', 'expiration' => $now - 10])
        ->all());
    DB::table('cache')->insert(['key' => 'live', 'value' => 's:1:"x";', 'expiration' => $now + 3600]);

    $this->artisan('cache:prune-expired', ['store' => 'database'])->assertSuccessful();

    expect(DB::table('cache')->pluck('key')->all())->toBe(['live']);
});

test('cache:prune-expired does nothing for a store that is not a database store', function () {
    DB::table('cache')->insert(['key' => 'expired', 'value' => 's:1:"x";', 'expiration' => now()->getTimestamp() - 10]);

    $this->artisan('cache:prune-expired', ['store' => 'array'])
        ->expectsOutputToContain('not a database store')
        ->assertSuccessful();

    expect(DB::table('cache')->count())->toBe(1);
});

test('cache:prune-expired is scheduled daily', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains($event->command, 'cache:prune-expired'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *');
});
