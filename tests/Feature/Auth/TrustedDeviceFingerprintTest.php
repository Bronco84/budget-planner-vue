<?php

use App\Mail\NewDeviceLogin;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\DeviceFingerprintService;
use App\Services\DeviceTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
 * AUTH-005 / AUTH-012. A trusted device is created by the JSON passkey-login
 * request but must be recognised on later HTML page loads from the same browser.
 */

const FINGERPRINT_BROWSER_HEADERS = [
    'User-Agent' => 'Mozilla/5.0 (Macintosh) Chrome/120.0 Safari/537.36',
    'Accept-Language' => 'en-US',
    'Accept-Encoding' => 'gzip',
];

function trustedDeviceFromJsonLogin(User $user, array $headers = [], ?string $cookieToken = null): TrustedDevice
{
    $headers = $headers + FINGERPRINT_BROWSER_HEADERS + ['Accept' => 'application/json'];

    $request = Request::create(
        '/webauthn/login',
        'POST',
        cookies: $cookieToken ? ['device_token' => $cookieToken] : [],
        server: collect($headers)
            ->mapWithKeys(fn ($value, $name) => ['HTTP_'.strtoupper(str_replace('-', '_', $name)) => $value])
            ->all(),
    );

    return app(DeviceTokenService::class)->createTrustedDevice($user, $request);
}

/**
 * A device stored with the fingerprint format used before versions were
 * dropped (full User-Agent, Accept-Language and Accept-Encoding).
 *
 * @return array{0: TrustedDevice, 1: string} the device and its raw cookie token
 */
function legacyFingerprintDevice(User $user, array $headers = FINGERPRINT_BROWSER_HEADERS): array
{
    $token = Str::random(64);

    $device = TrustedDevice::create([
        'user_id' => $user->id,
        'device_name' => 'Chrome on macOS',
        'device_fingerprint' => hash('sha256', implode('|', [
            $headers['User-Agent'], $headers['Accept-Language'], $headers['Accept-Encoding'],
        ])),
        'device_token' => Crypt::encryptString($token),
        'token_hash' => DeviceTokenService::hashToken($token),
        'ip_address' => '127.0.0.1',
        'user_agent' => $headers['User-Agent'],
        'last_used_at' => now()->subDay(),
        'expires_at' => now()->addDays(30),
        'auth_method' => 'passkey',
    ]);

    return [$device, $token];
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
        ->withCookie('device_token', Crypt::decryptString($current->device_token))
        ->post(route('trusted-devices.revoke-all'))
        ->assertSessionHas('status');

    expect(TrustedDevice::find($current->id))->not->toBeNull()
        ->and(TrustedDevice::find($other->id))->toBeNull();
});

test('a browser update keeps the device trusted', function () {
    Mail::fake();
    $user = User::factory()->create();
    createPasskey($user);
    $device = trustedDeviceFromJsonLogin($user);

    $this->withHeaders([
        'User-Agent' => 'Mozilla/5.0 (Macintosh) Chrome/121.0 Safari/537.36',
        'Accept-Encoding' => 'gzip, deflate, br, zstd',
    ] + FINGERPRINT_BROWSER_HEADERS + ['Accept' => 'text/html'])
        ->withCookie('device_token', Crypt::decryptString($device->device_token))
        ->get(route('login'))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('a device trusted with the legacy fingerprint still auto logs in and is moved to the current format', function () {
    $user = User::factory()->create();
    createPasskey($user);
    [$device, $token] = legacyFingerprintDevice($user);

    $this->withHeaders(FINGERPRINT_BROWSER_HEADERS + ['Accept' => 'text/html'])
        ->withCookie('device_token', $token)
        ->get(route('login'))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);

    $request = Request::create('/', server: ['HTTP_USER_AGENT' => FINGERPRINT_BROWSER_HEADERS['User-Agent']]);
    expect($device->fresh()->device_fingerprint)->toBe(app(DeviceFingerprintService::class)->generate($request));
});

test('signing in again from a legacy device whose browser has since updated reuses it without a new device email', function () {
    Mail::fake();
    $user = User::factory()->create();
    [$device, $token] = legacyFingerprintDevice($user);

    $again = trustedDeviceFromJsonLogin($user, ['User-Agent' => 'Mozilla/5.0 (Macintosh) Chrome/131.0 Safari/537.36'], $token);

    expect($again->id)->toBe($device->id)
        ->and($user->trustedDevices()->count())->toBe(1);
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

test('a second machine with the same browser and os is a new device and sends an alert', function () {
    Mail::fake();
    $user = User::factory()->create();
    $first = trustedDeviceFromJsonLogin($user);
    Mail::fake();

    $second = trustedDeviceFromJsonLogin($user);

    expect($second->id)->not->toBe($first->id)
        ->and($second->device_fingerprint)->toBe($first->device_fingerprint);
    Mail::assertSent(NewDeviceLogin::class, fn ($mail) => $mail->hasTo($user->email));
});

test('revoking all other devices revokes another machine with the same browser and os', function () {
    Mail::fake();
    $user = User::factory()->create();
    createPasskey($user);
    $current = trustedDeviceFromJsonLogin($user);
    $twin = trustedDeviceFromJsonLogin($user);

    $this->actingAs($user)
        ->withHeaders(FINGERPRINT_BROWSER_HEADERS)
        ->withCookie('device_token', Crypt::decryptString($current->device_token))
        ->post(route('trusted-devices.revoke-all'))
        ->assertSessionHas('status');

    expect(TrustedDevice::find($current->id))->not->toBeNull()
        ->and(TrustedDevice::find($twin->id))->toBeNull();
});

test('an iPhone is named as iOS, not macOS', function () {
    $request = Request::create('/', server: [
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1',
    ]);

    expect(app(DeviceFingerprintService::class)->generateDeviceName($request))->toBe('Safari on iOS');
});
