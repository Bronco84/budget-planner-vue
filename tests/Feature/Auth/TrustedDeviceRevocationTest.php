<?php

use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\DeviceFingerprintService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
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

/**
 * A stored (database driver) session belonging to the given user.
 */
function storedSession(string $id, ?User $user): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user?->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->getTimestamp(),
    ]);
}

function storedSessionIds(): array
{
    return DB::table('sessions')->orderBy('id')->pluck('id')->all();
}

test('a device auto sign-in records the session it signed in with', function () {
    $user = User::factory()->create();
    [$device, $token] = revocableDevice($user);

    $this->withHeaders(REVOCATION_HEADERS)
        ->withCookie('device_token', $token)
        ->get(route('login'))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect($device->fresh()->session_id)->not->toBeNull()->toBe(session()->getId());
});

test('revoking a device ends the live session it signed in with', function () {
    config(['session.driver' => 'database']);
    $owner = User::factory()->create();
    $other = User::factory()->create();
    [$device] = revocableDevice($owner);
    $device->update(['session_id' => 'intruder-session']);
    storedSession('intruder-session', $owner);
    storedSession('owner-laptop-session', $owner);
    storedSession('someone-else-session', $other);

    $this->actingAs($owner)
        ->delete(route('trusted-devices.revoke', $device))
        ->assertSessionHas('status');

    expect(TrustedDevice::find($device->id))->toBeNull()
        ->and(storedSessionIds())->not->toContain('intruder-session')
        ->and(storedSessionIds())->toContain('owner-laptop-session', 'someone-else-session', session()->getId());
});

test('revoking the device the request comes from keeps the current session', function () {
    config(['session.driver' => 'database']);
    $owner = User::factory()->create();
    [$device] = revocableDevice($owner);

    $this->actingAs($owner)->get(route('profile.edit'))->assertOk();
    $current = session()->getId();
    $device->update(['session_id' => $current]);

    $this->withCookie(config('session.cookie'), $current)
        ->delete(route('trusted-devices.revoke', $device))
        ->assertSessionHas('status');
    expect(session()->getId())->toBe($current);

    expect(storedSessionIds())->toContain($current);
    $this->assertAuthenticatedAs($owner);
});

test('revoking all other devices signs out every other session of the user', function () {
    config(['session.driver' => 'database']);
    $owner = User::factory()->create();
    $other = User::factory()->create();
    [$device] = revocableDevice($owner);
    $device->update(['session_id' => 'intruder-session']);
    storedSession('intruder-session', $owner);
    storedSession('magic-link-session', $owner);
    storedSession('someone-else-session', $other);

    $this->actingAs($owner)
        ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0'])
        ->post(route('trusted-devices.revoke-all'))
        ->assertSessionHas('status');

    expect(TrustedDevice::find($device->id))->toBeNull()
        ->and(storedSessionIds())->toBe(collect([session()->getId(), 'someone-else-session'])->sort()->values()->all());
    $this->assertAuthenticatedAs($owner);
});
