<?php

use App\Models\CalendarConnection;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Google\Client as GoogleClient;

/*
 * CALENDAR-002 token_refresh_failure: the real GoogleCalendarService runs
 * against an in-memory Google client, so an expired token that cannot be
 * refreshed is exercised end to end without ever contacting Google.
 */

/**
 * A GoogleCalendarService whose client refuses every refresh (revoked access)
 * and records whether any Calendar API call was attempted.
 */
function revokedGoogleCalendarService(): array
{
    $client = new class extends GoogleClient
    {
        public array $refreshAttempts = [];

        public int $apiCalls = 0;

        public function fetchAccessTokenWithRefreshToken($refreshToken = null)
        {
            $this->refreshAttempts[] = $refreshToken;

            return ['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'];
        }

        public function execute($request, $expectedClass = null)
        {
            $this->apiCalls++;

            throw new RuntimeException('No Calendar API call expected');
        }
    };

    $service = new GoogleCalendarService;
    (new ReflectionProperty(GoogleCalendarService::class, 'client'))->setValue($service, $client);

    return [$service, $client];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    [$service, $this->googleClient] = revokedGoogleCalendarService();
    app()->instance(GoogleCalendarService::class, $service);
});

test('syncing an expired connection whose refresh fails redirects back with a generic sync error', function (string $refreshToken) {
    $connection = CalendarConnection::create([
        'user_id' => $this->user->id,
        'provider' => 'google',
        'calendar_id' => 'primary',
        'calendar_name' => 'Personal',
        'access_token' => 'expired-token',
        'refresh_token' => $refreshToken,
        'token_expires_at' => now()->subHour(),
        'is_active' => true,
    ]);

    $this->actingAs($this->user)
        ->from(route('calendar.connections.index'))
        ->post(route('calendar.connections.sync', $connection))
        ->assertRedirect(route('calendar.connections.index'))
        ->assertSessionHas('error', 'Sync failed. Please try again.')
        ->assertSessionMissing('message');

    expect($this->googleClient->apiCalls)->toBe(0)
        ->and($this->googleClient->refreshAttempts)->toBe($refreshToken ? [$refreshToken] : [])
        ->and($connection->fresh()->access_token)->toBe('expired-token')
        ->and($connection->fresh()->last_synced_at)->toBeNull();
})->with([
    'revoked refresh token' => ['revoked-refresh'],
    'empty refresh token' => [''],
]);
