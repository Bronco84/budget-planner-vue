<?php

use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\DeviceFingerprintService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/*
 * AUTH-012: the device list empty state, and a revoked device's cookie no
 * longer signing the browser in.
 */

const REVOCATION_HEADERS = [
    'User-Agent' => 'Mozilla/5.0 (Macintosh) Firefox/130.0',
    'Accept' => 'text/html',
    'Accept-Language' => 'en-GB',
    'Accept-Encoding' => 'gzip',
];

/**
 * @return array{0: TrustedDevice, 1: string} the device and the raw cookie token
 */
function revocableDevice(User $user): array
{
    $request = Request::create('/', 'GET', server: collect(REVOCATION_HEADERS)
        ->mapWithKeys(fn ($value, $name) => ['HTTP_'.strtoupper(str_replace('-', '_', $name)) => $value])
        ->all());

    $token = Str::random(64);

    $device = TrustedDevice::create([
        'user_id' => $user->id,
        'device_name' => 'Firefox on macOS',
        'device_fingerprint' => app(DeviceFingerprintService::class)->generate($request),
        'device_token' => Crypt::encryptString($token),
        'ip_address' => '127.0.0.1',
        'user_agent' => REVOCATION_HEADERS['User-Agent'],
        'last_used_at' => now()->subDay(),
        'expires_at' => now()->addDays(30),
        'auth_method' => 'passkey',
    ]);

    return [$device, $token];
}

test('a user with no trusted devices sees an empty device list', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Profile/Edit')
            ->has('trustedDevices', 0));
});

test('a revoked device cookie no longer signs the browser in', function () {
    $user = User::factory()->create();
    [$device, $token] = revocableDevice($user);

    // The cookie works before revocation.
    $this->withHeaders(REVOCATION_HEADERS)
        ->withCookie('device_token', $token)
        ->get(route('login'))
        ->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);

    $this->withHeaders(REVOCATION_HEADERS)
        ->delete(route('trusted-devices.revoke', $device))
        ->assertSessionHas('status');
    expect(TrustedDevice::find($device->id))->toBeNull();

    // Fresh, signed-out browser presenting the old cookie.
    Auth::logout();
    $this->flushSession();
    Auth::forgetGuards();

    $this->withHeaders(REVOCATION_HEADERS)
        ->withCookie('device_token', $token)
        ->get(route('login'))
        ->assertOk();

    $this->assertGuest();
});
