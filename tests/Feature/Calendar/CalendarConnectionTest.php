<?php

use App\Models\CalendarConnection;
use App\Models\User;
use App\Services\GoogleCalendarService;

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
        ->assertSessionHas('success', 'Synced 3 events from Personal');
});

test('the owner can toggle a calendar connection', function () {
    $this->actingAs($this->user)
        ->from(route('calendar.connections.index'))
        ->post(route('calendar.connections.toggle', $this->connection))
        ->assertRedirect(route('calendar.connections.index'))
        ->assertSessionHas('success', 'Calendar connection disabled');

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
