<?php

use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\DeviceFingerprintService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/*
 * AUTH-005. Builds the device cookie the way DeviceTokenService does: the raw
 * token in the cookie, its encrypted form plus the request fingerprint stored.
 */

const AUTO_LOGIN_HEADERS = [
    'User-Agent' => 'Mozilla/5.0 (Macintosh) Chrome/120.0 Safari/537.36',
    'Accept' => 'text/html',
    'Accept-Language' => 'en-US',
    'Accept-Encoding' => 'gzip',
];

function autoLoginDeviceToken(User $user): string
{
    $request = Request::create('/', 'GET', server: collect(AUTO_LOGIN_HEADERS)
        ->mapWithKeys(fn ($value, $name) => ['HTTP_'.strtoupper(str_replace('-', '_', $name)) => $value])
        ->all());

    $token = Str::random(64);

    TrustedDevice::create([
        'user_id' => $user->id,
        'device_name' => 'Chrome on macOS',
        'device_fingerprint' => app(DeviceFingerprintService::class)->generate($request),
        'device_token' => Crypt::encryptString($token),
        'ip_address' => '127.0.0.1',
        'user_agent' => AUTO_LOGIN_HEADERS['User-Agent'],
        'last_used_at' => now()->subDay(),
        'expires_at' => now()->addDays(30),
        'auth_method' => 'passkey',
    ]);

    return $token;
}

test('a trusted device auto login regenerates the session id', function () {
    $user = User::factory()->create();
    $token = autoLoginDeviceToken($user);
    $guestSessionId = Str::random(40);

    $this->withHeaders(AUTO_LOGIN_HEADERS)
        ->withCookie(config('session.cookie'), $guestSessionId)
        ->withCookie('device_token', $token)
        ->get(route('login'))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($guestSessionId);
});

test('a guest request without a device cookie keeps its session id', function () {
    $guestSessionId = Str::random(40);

    $this->withHeaders(AUTO_LOGIN_HEADERS)
        ->withCookie(config('session.cookie'), $guestSessionId)
        ->get(route('login'))
        ->assertOk();

    $this->assertGuest();
    expect(session()->getId())->toBe($guestSessionId);
});
