<?php

use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\DeviceTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

/*
 * AUTH-005 / AUTH-012. A trusted device is created by the JSON passkey-login
 * request but must be recognised on later HTML page loads from the same browser.
 */

const FINGERPRINT_BROWSER_HEADERS = [
    'User-Agent' => 'Mozilla/5.0 (Macintosh) Chrome/120.0 Safari/537.36',
    'Accept-Language' => 'en-US',
    'Accept-Encoding' => 'gzip',
];

function trustedDeviceFromJsonLogin(User $user): TrustedDevice
{
    $headers = FINGERPRINT_BROWSER_HEADERS + ['Accept' => 'application/json'];

    $request = Request::create('/webauthn/login', 'POST', server: collect($headers)
        ->mapWithKeys(fn ($value, $name) => ['HTTP_'.strtoupper(str_replace('-', '_', $name)) => $value])
        ->all());

    return app(DeviceTokenService::class)->createTrustedDevice($user, $request);
}

test('a device trusted during a json passkey login auto logs in on a later html page load', function () {
    Mail::fake();
    $user = User::factory()->create();
    createPasskey($user);
    $device = trustedDeviceFromJsonLogin($user);

    $this->withHeaders(FINGERPRINT_BROWSER_HEADERS + ['Accept' => 'text/html,application/xhtml+xml'])
        ->withCookie('device_token', Crypt::decryptString($device->device_token))
        ->get(route('login'))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('a device token presented from a different browser does not auto log in', function () {
    Mail::fake();
    $user = User::factory()->create();
    $device = trustedDeviceFromJsonLogin($user);

    $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Firefox/121.0'] + FINGERPRINT_BROWSER_HEADERS)
        ->withCookie('device_token', Crypt::decryptString($device->device_token))
        ->get(route('login'))
        ->assertOk();

    $this->assertGuest();
});

test('revoking all other devices keeps the device for the current browser', function () {
    Mail::fake();
    $user = User::factory()->create();
    createPasskey($user);
    $current = trustedDeviceFromJsonLogin($user);
    $other = TrustedDevice::create([
        'user_id' => $user->id,
        'device_name' => 'Firefox on Windows',
        'device_fingerprint' => hash('sha256', 'another-browser'),
        'device_token' => Crypt::encryptString('other-token'),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Firefox',
        'last_used_at' => now(),
        'expires_at' => now()->addDays(30),
        'auth_method' => 'passkey',
    ]);

    $this->actingAs($user)
        ->withHeaders(FINGERPRINT_BROWSER_HEADERS + ['Accept' => 'text/html, application/xhtml+xml', 'X-Inertia' => 'true'])
        ->post(route('trusted-devices.revoke-all'))
        ->assertSessionHas('status');

    expect(TrustedDevice::find($current->id))->not->toBeNull()
        ->and(TrustedDevice::find($other->id))->toBeNull();
});
