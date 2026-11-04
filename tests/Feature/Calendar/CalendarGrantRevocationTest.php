<?php

use App\Models\CalendarConnection;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Google\Client as GoogleClient;
use Illuminate\Support\Facades\Log;

/*
 * CALENDAR-002 / PROFILE-002: removing a calendar connection, or the account
 * that owns it, revokes the Google grant. The real GoogleCalendarService runs
 * against an in-memory Google client, so nothing ever reaches Google.
 */

/**
 * Bind a GoogleCalendarService whose client records revocations, and
 * optionally fails them (refused, or a network error).
 */
function revocationRecordingGoogleClient(?string $failure = null): GoogleClient
{
    $client = new class extends GoogleClient
    {
        public array $revoked = [];

        public ?string $failure = null;

        public function revokeToken($token = null)
        {
            if ($this->failure === 'throw') {
                throw new RuntimeException('Google is unreachable');
            }

            $this->revoked[] = $token;

            return $this->failure !== 'refuse';
        }
    };
    $client->failure = $failure;

    $service = new GoogleCalendarService;
    (new ReflectionProperty(GoogleCalendarService::class, 'client'))->setValue($service, $client);
    app()->instance(GoogleCalendarService::class, $service);

    return $client;
}

function googleCalendarConnection(User $user, string $calendarId, string $refreshToken): CalendarConnection
{
    return CalendarConnection::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'calendar_id' => $calendarId,
        'calendar_name' => ucfirst($calendarId),
        'access_token' => "access-{$calendarId}",
        'refresh_token' => $refreshToken,
        'token_expires_at' => now()->addHour(),
        'is_active' => true,
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create(['password' => null]);
});

test('removing a calendar connection revokes its google grant', function () {
    $google = revocationRecordingGoogleClient();
    $connection = googleCalendarConnection($this->user, 'primary', 'refresh-primary');

    $this->actingAs($this->user)
        ->from(route('calendar.connections.index'))
        ->delete(route('calendar.connections.destroy', $connection))
        ->assertRedirect(route('calendar.connections.index'))
        ->assertSessionHas('message', 'Calendar connection removed');

    expect(CalendarConnection::find($connection->id))->toBeNull()
        ->and($google->revoked)->toBe(['refresh-primary']);
});

test('a failed google revocation still removes the calendar connection and is logged', function (string $failure) {
    revocationRecordingGoogleClient($failure);
    Log::spy();
    $connection = googleCalendarConnection($this->user, 'primary', 'refresh-primary');

    $this->actingAs($this->user)
        ->from(route('calendar.connections.index'))
        ->delete(route('calendar.connections.destroy', $connection))
        ->assertRedirect(route('calendar.connections.index'))
        ->assertSessionHas('message', 'Calendar connection removed');

    expect(CalendarConnection::find($connection->id))->toBeNull();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context) => str_contains($message, 'Google Calendar grant revocation')
            && $context['connection_id'] === $connection->id
            && ! str_contains(json_encode($context), 'refresh-primary')
            && ($failure === 'refuse' || ($context['trace'] ?? []) !== []))
        ->once();
})->with([
    'google refuses' => ['refuse'],
    'google unreachable' => ['throw'],
]);

test('removing one of two connections that share a google grant leaves the grant in place', function () {
    $google = revocationRecordingGoogleClient();
    $primary = googleCalendarConnection($this->user, 'primary', 'shared-refresh');
    googleCalendarConnection($this->user, 'work', 'shared-refresh');

    $this->actingAs($this->user)
        ->delete(route('calendar.connections.destroy', $primary))
        ->assertRedirect();

    expect(CalendarConnection::find($primary->id))->toBeNull()
        ->and($google->revoked)->toBe([]);
});

test('deleting an account revokes the google grant of each calendar connection', function () {
    $google = revocationRecordingGoogleClient();
    createPasskey($this->user);
    googleCalendarConnection($this->user, 'primary', 'refresh-primary');
    googleCalendarConnection($this->user, 'work', 'refresh-work');

    $this->actingAs($this->user)
        ->withSession(['auth' => ['authenticated_at' => now()->getTimestamp()]])
        ->delete(route('profile.destroy'), ['email' => $this->user->email])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    expect(User::find($this->user->id))->toBeNull()
        ->and(CalendarConnection::where('user_id', $this->user->id)->count())->toBe(0)
        ->and($google->revoked)->toEqualCanonicalizing(['refresh-primary', 'refresh-work']);
});

test('a failed google revocation does not block account deletion', function () {
    revocationRecordingGoogleClient('throw');
    createPasskey($this->user);
    googleCalendarConnection($this->user, 'primary', 'refresh-primary');

    $this->actingAs($this->user)
        ->withSession(['auth' => ['authenticated_at' => now()->getTimestamp()]])
        ->delete(route('profile.destroy'), ['email' => $this->user->email])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    expect(User::find($this->user->id))->toBeNull()
        ->and(CalendarConnection::where('user_id', $this->user->id)->count())->toBe(0);
});
