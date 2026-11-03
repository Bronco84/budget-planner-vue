<?php

use App\Providers\TelescopeServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\IncomingExceptionEntry;
use Laravel\Telescope\Telescope;

/*
 * phpunit disables Telescope, so this checks the provider's configuration
 * directly: outside local it records only failures (no request, mail or AI
 * payloads carrying financial data, magic links or API keys), masks
 * credentials, and the table is pruned daily.
 */

beforeEach(function () {
    $this->telescopeState = [Telescope::$hiddenRequestHeaders, Telescope::$hiddenRequestParameters, Telescope::$filterUsing];
    Telescope::$filterUsing = [];
});

afterEach(function () {
    [Telescope::$hiddenRequestHeaders, Telescope::$hiddenRequestParameters, Telescope::$filterUsing] = $this->telescopeState;
});

function telescopeRecords(string $type, array $content = []): bool
{
    $entry = $type === EntryType::EXCEPTION
        ? new IncomingExceptionEntry(new RuntimeException('boom'), $content)
        : IncomingEntry::make($content);

    return telescopeKeeps($entry->type($type));
}

function telescopeKeeps(IncomingEntry $entry): bool
{
    return collect(Telescope::$filterUsing)->every(fn (callable $filter) => $filter($entry));
}

test('outside local Telescope masks cookies in both directions and the AI API key', function () {
    app()->detectEnvironment(fn () => 'production');

    (new TelescopeServiceProvider(app()))->register();

    expect(Telescope::$hiddenRequestHeaders)->toContain('set-cookie', 'cookie', 'x-xsrf-token', 'x-api-key');
});

test('outside local Telescope records only exceptions, failed requests, failed jobs and slow queries', function () {
    app()->detectEnvironment(fn () => 'production');

    (new TelescopeServiceProvider(app()))->register();

    expect(telescopeRecords(EntryType::EXCEPTION))->toBeTrue()
        ->and(telescopeRecords(EntryType::REQUEST, ['response_status' => 500]))->toBeTrue()
        ->and(telescopeRecords(EntryType::JOB, ['status' => 'failed']))->toBeTrue()
        ->and(telescopeRecords(EntryType::QUERY, ['slow' => true]))->toBeTrue()
        ->and(telescopeRecords(EntryType::REQUEST, ['response_status' => 200]))->toBeFalse()
        ->and(telescopeRecords(EntryType::QUERY, ['slow' => false]))->toBeFalse()
        ->and(telescopeRecords(EntryType::JOB, ['status' => 'processed']))->toBeFalse()
        ->and(telescopeRecords(EntryType::MAIL))->toBeFalse()
        ->and(telescopeRecords(EntryType::CLIENT_REQUEST))->toBeFalse()
        ->and(telescopeRecords(EntryType::MODEL))->toBeFalse();
});

test('locally Telescope still records everything', function () {
    app()->detectEnvironment(fn () => 'local');

    (new TelescopeServiceProvider(app()))->register();

    expect(telescopeRecords(EntryType::MAIL))->toBeTrue()
        ->and(telescopeRecords(EntryType::REQUEST, ['response_status' => 200]))->toBeTrue();
});

test('Telescope entries are pruned after 48 hours every day', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'telescope:prune --hours=48'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *');
});
