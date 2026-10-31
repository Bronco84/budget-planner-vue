<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

/*
 * AUTH-011. The profile page lists the signed-in user's passkeys only.
 */

test('the profile page lists the users passkeys and not other users passkeys', function () {
    $user = User::factory()->create();
    $first = createPasskey($user, 'first-key');
    $first->forceFill(['alias' => 'MacBook', 'updated_at' => now()->subDay()])->save();
    $second = createPasskey($user, 'second-key');
    $second->forceFill(['alias' => null])->save();
    createPasskey(User::factory()->create(), 'someone-elses-key');

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Profile/Edit')
            ->has('passkeys', 2)
            ->where('passkeys.0.id', 'second-key')
            ->where('passkeys.0.name', 'Passkey')
            ->where('passkeys.1.id', 'first-key')
            ->where('passkeys.1.name', 'MacBook')
            ->has('passkeys.0.created_at')
            ->has('passkeys.0.last_used'));
});
