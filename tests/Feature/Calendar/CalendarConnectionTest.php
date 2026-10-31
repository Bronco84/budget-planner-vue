<?php

use App\Models\CalendarConnection;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia;

/*
 * CALENDAR-001 / CALENDAR-002. GoogleCalendarService is always mocked so no test
 * ever contacts Google.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->google = Mockery::mock(GoogleCalendarService::class);
    app()->instance(GoogleCalendarService::class, $this->google);

    $this->connection = CalendarConnection::create([
        'user_id' => $this->user->id,
        'provider' => 'google',
        'calendar_id' => 'primary',
        'calendar_name' => 'Personal',
        'access_token' => 'token',
        'refresh_token' => 'refresh',
        'token_expires_at' => now()->addHour(),
        'is_active' => true,
    ]);
});

test('the owner can sync a calendar connection', function () {
    $this->google->shouldReceive('syncEvents')
        ->once()
        ->withArgs(fn ($connection) => $connection->is($this->connection))
        ->andReturn(3);

    $this->actingAs($this->user)
        ->from(route('calendar.connections.index'))
        ->post(route('calendar.connections.sync', $this->connection))
        ->assertRedirect(route('calendar.connections.index'))
        ->assertSessionHas('message', 'Synced 3 events from Personal');
});

test('the owner can toggle a calendar connection', function () {
    $this->actingAs($this->user)
        ->from(route('calendar.connections.index'))
        ->post(route('calendar.connections.toggle', $this->connection))
        ->assertRedirect(route('calendar.connections.index'))
        ->assertSessionHas('message', 'Calendar connection disabled');

    expect($this->connection->fresh()->is_active)->toBeFalse();
});

test('the owner can remove a calendar connection', function () {
    $this->actingAs($this->user)
        ->from(route('calendar.connections.index'))
        ->delete(route('calendar.connections.destroy', $this->connection))
        ->assertRedirect(route('calendar.connections.index'));

    expect(CalendarConnection::find($this->connection->id))->toBeNull();
});

test('another user cannot sync toggle or remove a calendar connection', function () {
    $this->google->shouldNotReceive('syncEvents');
    $other = User::factory()->create();

    $this->actingAs($other)->post(route('calendar.connections.sync', $this->connection))->assertForbidden();
    $this->actingAs($other)->post(route('calendar.connections.toggle', $this->connection))->assertForbidden();
    $this->actingAs($other)->delete(route('calendar.connections.destroy', $this->connection))->assertForbidden();

    expect($this->connection->fresh()->is_active)->toBeTrue();
});

test('an unknown calendar connection returns 404', function () {
    $this->actingAs($this->user)
        ->post(route('calendar.connections.sync', 999999))
        ->assertNotFound();
});

test('a google callback without a code or error redirects to connections with an error', function () {
    $this->google->shouldNotReceive('handleCallback');

    $this->actingAs($this->user)
        ->get(route('calendar.google.callback'))
        ->assertRedirect(route('calendar.connections.index'))
        ->assertSessionHas('error');
});

test('connecting stores a random oauth state in the session and sends it to google', function () {
    $this->google->shouldReceive('getAuthorizationUrl')
        ->once()
        ->withArgs(fn ($user, $state) => $user->is($this->user) && is_string($state) && strlen($state) >= 40)
        ->andReturnUsing(fn ($user, $state) => 'https://accounts.google.com/o/oauth2/auth?state='.$state);

    $response = $this->actingAs($this->user)->get(route('calendar.connect.google'));

    $state = session('calendar_oauth_state');
    expect($state)->toBeString()->not->toContain('user_id');
    $response->assertRedirect('https://accounts.google.com/o/oauth2/auth?state='.$state);
});

test('a google callback with a mismatched state is rejected without a token exchange', function () {
    $this->google->shouldNotReceive('handleCallback');

    $this->actingAs($this->user)
        ->withSession(['calendar_oauth_state' => 'expected-state'])
        ->get(route('calendar.google.callback', ['code' => 'attacker-code', 'state' => 'forged-state']))
        ->assertRedirect(route('calendar.connections.index'))
        ->assertSessionHas('error')
        ->assertSessionMissing('calendar_oauth_state');
});

test('a google callback without a stored or returned state is rejected without a token exchange', function () {
    $this->google->shouldNotReceive('handleCallback');

    $this->actingAs($this->user)
        ->get(route('calendar.google.callback', ['code' => 'attacker-code', 'state' => '{"user_id":1}']))
        ->assertRedirect(route('calendar.connections.index'))
        ->assertSessionHas('error');

    $this->actingAs($this->user)
        ->withSession(['calendar_oauth_state' => 'expected-state'])
        ->get(route('calendar.google.callback', ['code' => 'attacker-code']))
        ->assertRedirect(route('calendar.connections.index'))
        ->assertSessionHas('error');
});

test('a google callback with a matching state stores the connection and flashes a visible message', function () {
    $this->google->shouldReceive('handleCallback')
        ->once()
        ->withArgs(fn ($code, $user) => $code === 'good-code' && $user->is($this->user))
        ->andReturn($this->connection);

    $this->actingAs($this->user)
        ->withSession(['calendar_oauth_state' => 'expected-state'])
        ->get(route('calendar.google.callback', ['code' => 'good-code', 'state' => 'expected-state']))
        ->assertRedirect(route('calendar.connections.index'))
        ->assertSessionHas('message', 'Successfully connected to Personal!')
        ->assertSessionMissing('calendar_oauth_state');
});

test('a failed token exchange shows a generic error and logs the real cause', function () {
    Log::spy();
    $this->google->shouldReceive('handleCallback')
        ->once()
        ->andThrow(new Exception('invalid_grant: internal google detail'));

    $this->actingAs($this->user)
        ->withSession(['calendar_oauth_state' => 'expected-state'])
        ->get(route('calendar.google.callback', ['code' => 'bad-code', 'state' => 'expected-state']))
        ->assertRedirect(route('calendar.connections.index'))
        ->assertSessionHas('error', fn ($error) => ! str_contains($error, 'internal google detail'));

    Log::shouldHaveReceived('error')->once();
});

test('the connections page receives the flashed message so it can be shown', function () {
    $this->actingAs($this->user)
        ->from(route('calendar.connections.index'))
        ->followingRedirects()
        ->post(route('calendar.connections.toggle', $this->connection))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Calendar/Connections')
            ->where('flash.message', 'Calendar connection disabled'));
});
