<?php

use App\Providers\TelescopeServiceProvider;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;

/*
 * phpunit disables Telescope, so this checks the provider's configuration
 * directly: outside local it must not record login credentials (the
 * set-cookie trusted-device token, magic-link and invitation mail bodies).
 */

beforeEach(function () {
    $this->telescopeState = [Telescope::$hiddenRequestHeaders, Telescope::$hiddenRequestParameters, Telescope::$filterUsing];
    Telescope::$filterUsing = [];
});

afterEach(function () {
    [Telescope::$hiddenRequestHeaders, Telescope::$hiddenRequestParameters, Telescope::$filterUsing] = $this->telescopeState;
});

function telescopeRecords(string $type): bool
{
    $entry = IncomingEntry::make([])->type($type);

    return collect(Telescope::$filterUsing)->every(fn (callable $filter) => $filter($entry));
}

test('outside local Telescope masks cookies in both directions and drops mail entries', function () {
    app()->detectEnvironment(fn () => 'production');

    (new TelescopeServiceProvider(app()))->register();

    expect(Telescope::$hiddenRequestHeaders)->toContain('set-cookie', 'cookie', 'x-xsrf-token')
        ->and(telescopeRecords(EntryType::MAIL))->toBeFalse()
        ->and(telescopeRecords(EntryType::REQUEST))->toBeTrue();
});

test('locally Telescope still records mail', function () {
    app()->detectEnvironment(fn () => 'local');

    (new TelescopeServiceProvider(app()))->register();

    expect(telescopeRecords(EntryType::MAIL))->toBeTrue();
});
