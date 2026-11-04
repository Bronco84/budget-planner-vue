<?php

use App\Models\CalendarConnection;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Google\Client as GoogleClient;
use Google\Service\Calendar\Calendar as GoogleCalendarResource;
use Google\Service\Calendar\Events as GoogleEvents;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/*
 * CALENDAR-001: Google OAuth tokens are encrypted at rest (as Plaid access
 * tokens are), and the data migration encrypts rows stored before the cast.
 */

function calendarTokenRow(int $id): object
{
    return DB::table('calendar_connections')->where('id', $id)->first(['access_token', 'refresh_token']);
}

test('google calendar tokens are encrypted at rest and read back as plaintext', function () {
    // The real callback path, against an in-memory Google client.
    $client = new class extends GoogleClient
    {
        public function fetchAccessTokenWithAuthCode($code, $codeVerifier = null)
        {
            return ['access_token' => 'ya29.access-token', 'refresh_token' => '1//refresh-token', 'expires_in' => 3600];
        }

        public function execute($request, $expectedClass = null)
        {
            return match ($expectedClass) {
                GoogleCalendarResource::class => new GoogleCalendarResource(['summary' => 'Personal']),
                GoogleEvents::class => new GoogleEvents(['items' => []]),
            };
        }
    };
    $service = new GoogleCalendarService;
    (new ReflectionProperty(GoogleCalendarService::class, 'client'))->setValue($service, $client);
    app()->instance(GoogleCalendarService::class, $service);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['calendar_oauth_state' => 'expected-state'])
        ->get(route('calendar.google.callback', ['code' => 'good-code', 'state' => 'expected-state']))
        ->assertSessionHas('message');

    $connection = CalendarConnection::where('user_id', $user->id)->sole();
    $raw = calendarTokenRow($connection->id);

    expect($raw->access_token)->not->toBe('ya29.access-token')
        ->and($raw->refresh_token)->not->toBe('1//refresh-token')
        ->and(Crypt::decryptString($raw->access_token))->toBe('ya29.access-token')
        ->and(Crypt::decryptString($raw->refresh_token))->toBe('1//refresh-token')
        ->and($connection->access_token)->toBe('ya29.access-token')
        ->and($connection->refresh_token)->toBe('1//refresh-token');
});

test('the token encryption migration encrypts existing plaintext rows and its rollback restores them', function () {
    $migration = require database_path('migrations/2026_11_04_000060_encrypt_calendar_connection_tokens.php');
    $userId = User::factory()->create()->id;
    $alreadyEncrypted = Crypt::encryptString('already-encrypted');

    $plainId = DB::table('calendar_connections')->insertGetId([
        'user_id' => $userId, 'provider' => 'google', 'calendar_id' => 'primary',
        'access_token' => 'plain-access', 'refresh_token' => '', 'is_active' => true,
    ]);
    $mixedId = DB::table('calendar_connections')->insertGetId([
        'user_id' => $userId, 'provider' => 'google', 'calendar_id' => 'work',
        'access_token' => $alreadyEncrypted, 'refresh_token' => 'plain-refresh', 'is_active' => true,
    ]);

    $migration->up();

    expect(calendarTokenRow($plainId)->access_token)->not->toBe('plain-access')
        ->and(calendarTokenRow($mixedId)->access_token)->toBe($alreadyEncrypted)
        ->and(CalendarConnection::find($plainId)->access_token)->toBe('plain-access')
        ->and(CalendarConnection::find($plainId)->refresh_token)->toBe('')
        ->and(CalendarConnection::find($mixedId)->access_token)->toBe('already-encrypted')
        ->and(CalendarConnection::find($mixedId)->refresh_token)->toBe('plain-refresh');

    $migration->down();

    expect((array) calendarTokenRow($plainId))->toBe(['access_token' => 'plain-access', 'refresh_token' => ''])
        ->and((array) calendarTokenRow($mixedId))->toBe(['access_token' => 'already-encrypted', 'refresh_token' => 'plain-refresh']);
});
