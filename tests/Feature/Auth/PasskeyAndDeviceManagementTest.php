<?php

use App\Mail\NewDeviceLogin;
use App\Models\Budget;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Support\Str;

function trustedDevice(User $user, array $overrides = []): TrustedDevice
{
    return TrustedDevice::create(array_merge([
        'user_id' => $user->id,
        'device_name' => 'Laptop',
        'device_fingerprint' => Str::random(40),
        'device_token' => hash('sha256', Str::random(40)),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'last_used_at' => now(),
        'expires_at' => now()->addDays(30),
        'auth_method' => 'passkey',
    ], $overrides));
}

test('a user can rename their passkey', function () {
    $user = User::factory()->create();
    $passkey = createPasskey($user);

    $this->actingAs($user)
        ->patch(route('passkeys.update', $passkey), ['name' => 'Work YubiKey'])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($passkey->fresh()->alias)->toBe('Work YubiKey');
});

test('renaming a passkey requires a name', function () {
    $user = User::factory()->create();
    $passkey = createPasskey($user);

    $this->actingAs($user)
        ->patch(route('passkeys.update', $passkey), ['name' => ''])
        ->assertSessionHasErrors('name');

    expect($passkey->fresh()->alias)->toBe('Test key');
});

test('a user cannot rename another users passkey', function () {
    $owner = User::factory()->create();
    $passkey = createPasskey($owner);

    $this->actingAs(User::factory()->create())
        ->patch(route('passkeys.update', $passkey), ['name' => 'Hijacked'])
        ->assertForbidden();

    expect($passkey->fresh()->alias)->toBe('Test key');
});

test('a user cannot delete their last passkey', function () {
    $user = User::factory()->create();
    $passkey = createPasskey($user);

    $this->actingAs($user)
        ->delete(route('passkeys.destroy', $passkey))
        ->assertSessionHasErrors('passkey');

    expect($user->webAuthnCredentials()->count())->toBe(1);
});

test('a user can delete a passkey when another remains', function () {
    $user = User::factory()->create();
    $passkey = createPasskey($user);
    createPasskey($user);

    $this->actingAs($user)
        ->delete(route('passkeys.destroy', $passkey))
        ->assertSessionHas('status');

    expect($user->webAuthnCredentials()->pluck('id'))->not->toContain($passkey->id)
        ->and($user->webAuthnCredentials()->count())->toBe(1);
});

test('a user cannot delete another users passkey', function () {
    $owner = User::factory()->create();
    $passkey = createPasskey($owner);
    createPasskey($owner);

    $this->actingAs(User::factory()->create())
        ->delete(route('passkeys.destroy', $passkey))
        ->assertForbidden();

    expect($owner->webAuthnCredentials()->count())->toBe(2);
});

test('a user can revoke one of their trusted devices', function () {
    $user = User::factory()->create();
    $device = trustedDevice($user);

    $this->actingAs($user)
        ->delete(route('trusted-devices.revoke', $device))
        ->assertSessionHas('status');

    expect(TrustedDevice::find($device->id))->toBeNull();
});

test('a user cannot revoke another users trusted device', function () {
    $device = trustedDevice(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->delete(route('trusted-devices.revoke', $device))
        ->assertForbidden();

    expect(TrustedDevice::find($device->id))->not->toBeNull();
});

test('a user can switch their active budget', function () {
    $user = User::factory()->create();
    $first = Budget::factory()->create(['user_id' => $user->id]);
    $second = Budget::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson(route('preferences.active-budget.set'), ['budget_id' => $second->id])
        ->assertOk()
        ->assertJsonPath('active_budget.id', $second->id);

    expect($user->fresh()->getActiveBudget()->id)->toBe($second->id)
        ->and($first->id)->not->toBe($second->id);
});

test('a user cannot activate another users budget', function () {
    $user = User::factory()->create();
    $mine = Budget::factory()->create(['user_id' => $user->id]);
    $theirs = Budget::factory()->create(['user_id' => User::factory()->create()->id]);
    $user->setActiveBudget($mine->id);

    $this->actingAs($user)
        ->postJson(route('preferences.active-budget.set'), ['budget_id' => $theirs->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('budget_id');

    expect($user->fresh()->getActiveBudget()->id)->toBe($mine->id);
});

test('the new device login email links to the profile page to manage devices', function () {
    $user = User::factory()->create();
    $device = trustedDevice($user);

    $html = (new NewDeviceLogin($user, $device))->render();

    expect($html)->toContain(route('profile.edit'));
});
