<?php

use App\Models\User;

/*
 * Deletes through Nova's HTTP API must not skip the app's deletion services.
 * PR-079: users cannot be deleted from Nova at all (they delete their own
 * accounts from Profile, through AccountDeletionService).
 */

function novaDeletionAdmin(): User
{
    $admin = User::factory()->create();
    // is_admin is deliberately not mass-assignable.
    $admin->is_admin = true;
    $admin->save();

    return $admin;
}

test('an admin cannot delete a user through nova', function () {
    $admin = novaDeletionAdmin();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->deleteJson('/nova-api/users?resources[]='.$user->id)
        ->assertOk();
    $this->actingAs($admin)
        ->deleteJson('/nova-api/users/force?resources[]='.$user->id);
    $this->actingAs($admin)
        ->deleteJson('/nova-api/users?resources=all')
        ->assertOk();

    expect(User::find($user->id))->not->toBeNull()
        ->and(User::find($admin->id))->not->toBeNull();
});

test('nova does not offer deleting a user', function () {
    $admin = novaDeletionAdmin();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->getJson('/nova-api/users/'.$user->id)
        ->assertOk()
        ->assertJsonPath('resource.authorizedToDelete', false)
        ->assertJsonPath('resource.authorizedToForceDelete', false);
});
