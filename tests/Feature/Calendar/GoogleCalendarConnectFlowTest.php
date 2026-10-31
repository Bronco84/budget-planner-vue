<?php

use App\Models\CalendarConnection;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Google\Client as GoogleClient;
use Google\Service\Calendar\Calendar as GoogleCalendarResource;
use Google\Service\Calendar\Events as GoogleEvents;
use Inertia\Testing\AssertableInertia;

/*
 * CALENDAR-001. The connections page, consent denial, and the real
 * handleCallback flow run against an in-memory Google client so no request
 * ever leaves the test.
 */

/**
 * A GoogleCalendarService whose Google client answers token exchanges and API
 * calls from memory. Each auth code maps to the token Google would return.
 */
function offlineGoogleCalendarService(array $tokensByCode, array $events = []): GoogleCalendarService
{
    $client = new class extends GoogleClient
    {
        public array $tokensByCode = [];

        public array $events = [];

        public function fetchAccessTokenWithAuthCode($code, $codeVerifier = null)
        {
            return $this->tokensByCode[$code] ?? ['error' => 'invalid_grant'];
        }

        public function execute($request, $expectedClass = null)
        {
            return match ($expectedClass) {
                GoogleCalendarResource::class => new GoogleCalendarResource(['summary' => 'Personal']),
                GoogleEvents::class => new GoogleEvents(['items' => $this->events]),
            };
        }
    };
    $client->tokensByCode = $tokensByCode;
    $client->events = $events;

    $service = new GoogleCalendarService;
    $property = new ReflectionProperty(GoogleCalendarService::class, 'client');
    $property->setValue($service, $client);

    return $service;
}

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('the connections page lists only the users calendar connections', function () {
    $mine = CalendarConnection::create([
        'user_id' => $this->user->id,
        'provider' => 'google',
        'calendar_id' => 'primary',
        'calendar_name' => 'Personal',
        'access_token' => 'token',
        'refresh_token' => 'refresh',
        'is_active' => true,
    ]);
    CalendarConnection::create([
        'user_id' => User::factory()->create()->id,
        'provider' => 'google',
        'calendar_id' => 'primary',
        'calendar_name' => 'Someone else',
        'access_token' => 'token',
        'refresh_token' => 'refresh',
        'is_active' => true,
    ]);

    $this->actingAs($this->user)
        ->get(route('calendar.connections.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Calendar/Connections')
            ->has('connections', 1)
            ->where('connections.0.id', $mine->id)
            ->where('connections.0.calendar_name', 'Personal')
            ->where('connections.0.is_active', true)
            ->where('connections.0.events_count', 0)
            ->missing('connections.0.access_token'));
});

test('the connections page renders an empty list when nothing is connected', function () {
    $this->actingAs($this->user)
        ->get(route('calendar.connections.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Calendar/Connections')
            ->has('connections', 0));
});

test('a denied google consent returns to connections with an error and no token exchange', function () {
    $google = Mockery::mock(GoogleCalendarService::class);
    $google->shouldNotReceive('handleCallback');
    app()->instance(GoogleCalendarService::class, $google);

    $this->actingAs($this->user)
        ->withSession(['calendar_oauth_state' => 'expected-state'])
        ->get(route('calendar.google.callback', ['error' => 'access_denied', 'state' => 'expected-state']))
        ->assertRedirect(route('calendar.connections.index'))
        ->assertSessionHas('error', 'Calendar connection was cancelled or failed.')
        ->assertSessionMissing('calendar_oauth_state');

    expect(CalendarConnection::count())->toBe(0);
});

test('a google callback exchanges the code, stores the connection and runs the initial sync', function () {
    $this->freezeTime();
    app()->instance(GoogleCalendarService::class, offlineGoogleCalendarService(
        ['good-code' => ['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600]],
        [[
            'id' => 'evt-1',
            'summary' => 'Rent due',
            'start' => ['date' => now()->addDays(3)->toDateString()],
            'end' => ['date' => now()->addDays(4)->toDateString()],
            'updated' => now()->toRfc3339String(),
        ]],
    ));

    $this->actingAs($this->user)
        ->withSession(['calendar_oauth_state' => 'expected-state'])
        ->get(route('calendar.google.callback', ['code' => 'good-code', 'state' => 'expected-state']))
        ->assertRedirect(route('calendar.connections.index'))
        ->assertSessionHas('message', 'Successfully connected to Personal!');

    $connection = CalendarConnection::where('user_id', $this->user->id)->sole();
    expect($connection->calendar_id)->toBe('primary')
        ->and($connection->calendar_name)->toBe('Personal')
        ->and($connection->access_token)->toBe('access-1')
        ->and($connection->refresh_token)->toBe('refresh-1')
        ->and($connection->token_expires_at->timestamp)->toBe(now()->addHour()->timestamp)
        ->and($connection->is_active)->toBeTrue()
        ->and($connection->last_synced_at)->not->toBeNull();

    $event = CalendarEvent::sole();
    expect($event->calendar_connection_id)->toBe($connection->id)
        ->and($event->title)->toBe('Rent due')
        ->and((bool) $event->all_day)->toBeTrue();
});

test('reconnecting the same calendar updates the existing connection instead of duplicating it', function () {
    $existing = CalendarConnection::create([
        'user_id' => $this->user->id,
        'provider' => 'google',
        'calendar_id' => 'primary',
        'calendar_name' => 'Old name',
        'access_token' => 'stale-access',
        'refresh_token' => 'stale-refresh',
        'token_expires_at' => now()->subDay(),
        'is_active' => false,
    ]);
    app()->instance(GoogleCalendarService::class, offlineGoogleCalendarService(
        ['new-code' => ['access_token' => 'fresh-access', 'refresh_token' => 'fresh-refresh', 'expires_in' => 3600]],
    ));

    $this->actingAs($this->user)
        ->withSession(['calendar_oauth_state' => 'expected-state'])
        ->get(route('calendar.google.callback', ['code' => 'new-code', 'state' => 'expected-state']))
        ->assertRedirect(route('calendar.connections.index'))
        ->assertSessionHas('message');

    $connection = CalendarConnection::where('user_id', $this->user->id)->sole();
    expect($connection->id)->toBe($existing->id)
        ->and($connection->access_token)->toBe('fresh-access')
        ->and($connection->refresh_token)->toBe('fresh-refresh')
        ->and($connection->calendar_name)->toBe('Personal')
        ->and($connection->is_active)->toBeTrue();
});

test('reconnecting when google omits the refresh token keeps the stored one', function () {
    $existing = CalendarConnection::create([
        'user_id' => $this->user->id,
        'provider' => 'google',
        'calendar_id' => 'primary',
        'calendar_name' => 'Personal',
        'access_token' => 'stale-access',
        'refresh_token' => 'kept-refresh',
        'token_expires_at' => now()->subDay(),
        'is_active' => true,
    ]);
    app()->instance(GoogleCalendarService::class, offlineGoogleCalendarService(
        ['again-code' => ['access_token' => 'fresh-access', 'expires_in' => 3600]],
    ));

    $this->actingAs($this->user)
        ->withSession(['calendar_oauth_state' => 'expected-state'])
        ->get(route('calendar.google.callback', ['code' => 'again-code', 'state' => 'expected-state']))
        ->assertRedirect(route('calendar.connections.index'))
        ->assertSessionHas('message');

    $connection = $existing->fresh();
    expect($connection->access_token)->toBe('fresh-access')
        ->and($connection->refresh_token)->toBe('kept-refresh');
});
