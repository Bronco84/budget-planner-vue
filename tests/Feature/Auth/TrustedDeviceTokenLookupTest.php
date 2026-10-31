<?php

use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\DeviceFingerprintService;
use App\Services\DeviceTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
 * AUTH-005. verifyDeviceToken looks devices up by an indexed sha256 token hash
 * instead of decrypting every trusted device; legacy rows without a hash still
 * verify (and get a hash on first match).
 */

function tokenLookupRequest(): Request
{
    return Request::create('/', 'GET', server: [
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh) Chrome/120.0 Safari/537.36',
        'HTTP_ACCEPT_LANGUAGE' => 'en-US',
        'HTTP_ACCEPT_ENCODING' => 'gzip',
    ]);
}

function legacyTrustedDevice(User $user, string $token, Request $request): TrustedDevice
{
    return TrustedDevice::create([
        'user_id' => $user->id,
        'device_name' => 'Chrome on macOS',
        'device_fingerprint' => app(DeviceFingerprintService::class)->generate($request),
        'device_token' => Crypt::encryptString($token),
        'last_used_at' => now(),
        'expires_at' => now()->addDays(30),
        'auth_method' => 'passkey',
    ]);
}

test('a newly trusted device stores a token hash and verifies via an indexed lookup without scanning other devices', function () {
    Mail::fake();
    $request = tokenLookupRequest();
    $service = app(DeviceTokenService::class);

    // Noise: other users' hashed devices that must not be decrypted.
    foreach (range(1, 5) as $i) {
        $service->createTrustedDevice(User::factory()->create(), Request::create('/', 'GET', server: ['HTTP_USER_AGENT' => "Agent {$i}"]));
    }

    $user = User::factory()->create();
    $device = $service->createTrustedDevice($user, $request);
    $token = Crypt::decryptString($device->device_token);

    expect($device->fresh()->token_hash)->toBe(hash('sha256', $token));

    DB::enableQueryLog();
    $found = $service->verifyDeviceToken($token, $request);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($found?->id)->toBe($device->id)
        ->and($queries)->toHaveCount(1)
        ->and($queries[0]['query'])->toContain('token_hash');
});

test('a legacy device without a token hash still verifies and gets its hash filled', function () {
    $request = tokenLookupRequest();
    $token = Str::random(64);
    $device = legacyTrustedDevice(User::factory()->create(), $token, $request);

    expect($device->token_hash)->toBeNull();

    $found = app(DeviceTokenService::class)->verifyDeviceToken($token, $request);

    expect($found?->id)->toBe($device->id)
        ->and($device->fresh()->token_hash)->toBe(hash('sha256', $token));
});

test('a wrong device token does not verify against hashed or legacy devices', function () {
    Mail::fake();
    $request = tokenLookupRequest();
    $service = app(DeviceTokenService::class);
    $service->createTrustedDevice(User::factory()->create(), $request);
    legacyTrustedDevice(User::factory()->create(), Str::random(64), $request);

    expect($service->verifyDeviceToken(Str::random(64), $request))->toBeNull();
});

test('an unknown device cookie does not auto log in', function () {
    Mail::fake();
    app(DeviceTokenService::class)->createTrustedDevice(User::factory()->create(), tokenLookupRequest());

    $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh) Chrome/120.0 Safari/537.36', 'Accept' => 'text/html'])
        ->withCookie('device_token', Str::random(64))
        ->get(route('login'))
        ->assertOk();

    $this->assertGuest();
});
