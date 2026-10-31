<?php

use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\DeviceFingerprintService;
use App\Services\DeviceTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/*
 * AUTH-005 / AUTH-006. Trusted-device auto-login across its lifecycle: skipping
 * the login page, expiry, revocation and sign-out.
 */

const LIFECYCLE_DEVICE_HEADERS = [
    'User-Agent' => 'Mozilla/5.0 (Macintosh) Chrome/120.0 Safari/537.36',
    'Accept' => 'text/html',
    'Accept-Language' => 'en-US',
    'Accept-Encoding' => 'gzip',
];

/**
 * Store a trusted device for the user the way DeviceTokenService does and
 * return [device, raw cookie token].
 */
function lifecycleTrustedDevice(User $user, array $overrides = []): array
{
    $request = Request::create('/', 'GET', server: collect(LIFECYCLE_DEVICE_HEADERS)
        ->mapWithKeys(fn ($value, $name) => ['HTTP_'.strtoupper(str_replace('-', '_', $name)) => $value])
        ->all());

    $token = Str::random(64);

    $device = TrustedDevice::create(array_merge([
        'user_id' => $user->id,
        'device_name' => 'Chrome on macOS',
        'device_fingerprint' => app(DeviceFingerprintService::class)->generate($request),
        'device_token' => Crypt::encryptString($token),
        'token_hash' => DeviceTokenService::hashToken($token),
        'ip_address' => '127.0.0.1',
        'user_agent' => LIFECYCLE_DEVICE_HEADERS['User-Agent'],
        'last_used_at' => now()->subDay(),
        'expires_at' => now()->addDays(30),
        'auth_method' => 'passkey',
    ], $overrides));

    return [$device, $token];
}

test('an auto logged in visit to the login page goes through the dashboard to the budgets index', function () {
    $user = User::factory()->create();
    createPasskey($user);
    [, $token] = lifecycleTrustedDevice($user);

    $this->withHeaders(LIFECYCLE_DEVICE_HEADERS)
        ->withCookie('device_token', $token)
        ->get(route('login'))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);

    $this->withHeaders(LIFECYCLE_DEVICE_HEADERS)
        ->withCookie('device_token', $token)
        ->get(route('dashboard'))
        ->assertRedirect(route('budgets.index'));
});

test('an expired trusted device leaves the request as a guest', function () {
    $user = User::factory()->create();
    [, $token] = lifecycleTrustedDevice($user, ['expires_at' => now()->addDays(30)]);

    $this->travel(31)->days();

    $this->withHeaders(LIFECYCLE_DEVICE_HEADERS)
        ->withCookie('device_token', $token)
        ->get(route('login'))
        ->assertOk();

    $this->assertGuest();
});

test('a revoked trusted device no longer signs the user in', function () {
    $user = User::factory()->create();
    [$device, $token] = lifecycleTrustedDevice($user);

    $this->actingAs($user)
        ->delete(route('trusted-devices.revoke', $device))
        ->assertRedirect();
    auth()->logout();

    $this->withHeaders(LIFECYCLE_DEVICE_HEADERS)
        ->withCookie('device_token', $token)
        ->get(route('login'))
        ->assertOk();

    $this->assertGuest();
});

test('after signing out the device cookie no longer signs the user in', function () {
    $user = User::factory()->create();
    [, $token] = lifecycleTrustedDevice($user);

    $this->withHeaders(LIFECYCLE_DEVICE_HEADERS)
        ->withCookie('device_token', $token)
        ->get(route('login'));
    $this->assertAuthenticatedAs($user);

    $this->withHeaders(LIFECYCLE_DEVICE_HEADERS)
        ->withCookie('device_token', $token)
        ->post(route('logout'))
        ->assertRedirect('/login');
    $this->assertGuest();

    $this->withHeaders(LIFECYCLE_DEVICE_HEADERS)
        ->withCookie('device_token', $token)
        ->get(route('login'))
        ->assertOk();

    $this->assertGuest();
});

test('signing out revokes the current trusted device, forgets its cookie and keeps other devices', function () {
    $user = User::factory()->create();
    [$current, $token] = lifecycleTrustedDevice($user);
    [$other] = lifecycleTrustedDevice($user, ['device_fingerprint' => 'another-browser', 'device_name' => 'Safari on iPhone']);

    $response = $this->actingAs($user)
        ->withHeaders(LIFECYCLE_DEVICE_HEADERS)
        ->withCookie('device_token', $token)
        ->post(route('logout'))
        ->assertRedirect('/login');

    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'device_token');
    expect($cookie)->not->toBeNull()
        ->and($cookie->getExpiresTime())->toBeLessThan(time());

    $this->assertGuest();
    expect(TrustedDevice::find($current->id))->toBeNull()
        ->and(TrustedDevice::find($other->id))->not->toBeNull();
});

test('a guest posting to logout is redirected to the login page', function () {
    $this->post(route('logout'))->assertRedirect(route('login'));

    $this->assertGuest();
});
