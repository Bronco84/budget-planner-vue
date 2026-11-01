<?php

use App\Models\CalendarConnection;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Google\Client as GoogleClient;
use Google\Service\Calendar\Event as GoogleEvent;
use Google\Service\Calendar\Events as GoogleEvents;

/*
 * CALENDAR-002: the real GoogleCalendarService::syncEvents runs against an
 * in-memory Google client that serves events.list pages, so the upsert and
 * prune logic is exercised without contacting Google.
 */

/**
 * A GoogleCalendarService whose client answers events.list with the given
 * pages (each a list of event arrays), or throws on the page index in $failOnPage.
 */
function fakeGoogleCalendarService(array $pages, ?int $failOnPage = null): array
{
    $client = new class extends GoogleClient
    {
        public array $pages = [];

        public ?int $failOnPage = null;

        public array $requestedPageTokens = [];

        public function execute($request, $expectedClass = null)
        {
            parse_str((string) $request->getUri()->getQuery(), $query);
            $token = $query['pageToken'] ?? null;
            $this->requestedPageTokens[] = $token;
            $index = $token === null ? 0 : (int) str_replace('page-', '', $token);

            if ($index === $this->failOnPage) {
                throw new RuntimeException('Google API unavailable');
            }

            $events = new GoogleEvents;
            $events->setItems(array_map(function (array $item) {
                $event = new GoogleEvent($item + ['status' => 'confirmed', 'updated' => now()->toRfc3339String()]);

                return $event;
            }, $this->pages[$index]));

            if (isset($this->pages[$index + 1])) {
                $events->setNextPageToken('page-'.($index + 1));
            }

            return $events;
        }
    };
    $client->pages = $pages;
    $client->failOnPage = $failOnPage;

    $service = new GoogleCalendarService;
    (new ReflectionProperty(GoogleCalendarService::class, 'client'))->setValue($service, $client);

    return [$service, $client];
}

function syncTestGoogleEvent(string $id, string $start, string $title = 'Event'): array
{
    return [
        'id' => $id,
        'summary' => $title,
        'start' => ['dateTime' => $start],
        'end' => ['dateTime' => $start],
    ];
}

function syncTestConnection(User $user, string $calendarId = 'primary'): CalendarConnection
{
    return CalendarConnection::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'calendar_id' => $calendarId,
        'calendar_name' => 'Personal',
        'access_token' => 'valid-token',
        'refresh_token' => 'refresh',
        'token_expires_at' => now()->addHour(),
        'is_active' => true,
    ]);
}

function syncTestStoredEvent(CalendarConnection $connection, string $googleId, string $start): CalendarEvent
{
    return CalendarEvent::create([
        'calendar_connection_id' => $connection->id,
        'user_id' => $connection->user_id,
        'google_event_id' => $googleId,
        'title' => 'Stored '.$googleId,
        'start_date' => $start,
        'google_updated_at' => now()->subDay(),
    ]);
}

test('sync walks every page and removes window events that google no longer returns', function () {
    $user = User::factory()->create();
    $connection = syncTestConnection($user);
    $inWindow = now()->addDays(3)->startOfHour();
    $outsideWindow = now()->subMonths(3);

    syncTestStoredEvent($connection, 'kept', $inWindow->toDateTimeString());
    syncTestStoredEvent($connection, 'deleted-in-google', $inWindow->toDateTimeString());
    syncTestStoredEvent($connection, 'older-than-window', $outsideWindow->toDateTimeString());

    [$service, $client] = fakeGoogleCalendarService([
        [syncTestGoogleEvent('kept', $inWindow->toRfc3339String(), 'Kept')],
        [syncTestGoogleEvent('second-page', $inWindow->copy()->addDay()->toRfc3339String(), 'Second page')],
    ]);

    expect($service->syncEvents($connection))->toBe(2);

    expect($client->requestedPageTokens)->toBe([null, 'page-1'])
        ->and(CalendarEvent::pluck('google_event_id')->sort()->values()->all())
        ->toBe(['kept', 'older-than-window', 'second-page'])
        ->and(CalendarEvent::firstWhere('google_event_id', 'kept')->title)->toBe('Kept')
        ->and($connection->fresh()->last_synced_at)->not->toBeNull();
});

test('a failed page leaves stored events untouched', function () {
    $user = User::factory()->create();
    $connection = syncTestConnection($user);
    $inWindow = now()->addDays(3)->startOfHour();
    syncTestStoredEvent($connection, 'only-on-page-two', $inWindow->toDateTimeString());

    [$service] = fakeGoogleCalendarService([
        [syncTestGoogleEvent('first', $inWindow->toRfc3339String())],
        [syncTestGoogleEvent('only-on-page-two', $inWindow->toRfc3339String())],
    ], failOnPage: 1);

    expect(fn () => $service->syncEvents($connection))->toThrow(RuntimeException::class);

    expect(CalendarEvent::where('google_event_id', 'only-on-page-two')->exists())->toBeTrue()
        ->and($connection->fresh()->last_synced_at)->toBeNull();
});

test('pruning only touches the syncing connection', function () {
    $user = User::factory()->create();
    $connection = syncTestConnection($user);
    $otherConnection = syncTestConnection(User::factory()->create());
    $inWindow = now()->addDays(3)->startOfHour();
    syncTestStoredEvent($otherConnection, 'someone-elses', $inWindow->toDateTimeString());

    [$service] = fakeGoogleCalendarService([[]]);

    expect($service->syncEvents($connection))->toBe(0)
        ->and(CalendarEvent::where('google_event_id', 'someone-elses')->exists())->toBeTrue();
});

test('an event shared by two users is stored once for each of their connections', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $aliceConnection = syncTestConnection($alice);
    $bobConnection = syncTestConnection($bob);
    $start = now()->addDays(3)->startOfHour()->toRfc3339String();

    [$service] = fakeGoogleCalendarService([[syncTestGoogleEvent('shared-meeting', $start, 'Shared meeting')]]);

    $service->syncEvents($aliceConnection);
    $service->syncEvents($bobConnection);
    $service->syncEvents($aliceConnection);

    expect(CalendarEvent::where('google_event_id', 'shared-meeting')->count())->toBe(2)
        ->and(CalendarEvent::where('user_id', $alice->id)->where('calendar_connection_id', $aliceConnection->id)->pluck('google_event_id')->all())
        ->toBe(['shared-meeting'])
        ->and(CalendarEvent::where('user_id', $bob->id)->where('calendar_connection_id', $bobConnection->id)->pluck('google_event_id')->all())
        ->toBe(['shared-meeting']);
});
