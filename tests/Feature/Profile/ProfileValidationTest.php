<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

/*
 * PROFILE-001: a missing name or a malformed, uppercase or already-taken email is
 * rejected and the profile is left unchanged.
 */

test('updating the profile with a missing name or invalid email is rejected', function (array $payload, string $field) {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create([
        'name' => 'Original Name',
        'email' => 'original@example.com',
    ]);
    $verifiedAt = $user->email_verified_at;

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), array_merge([
            'name' => 'New Name',
            'email' => 'new@example.com',
        ], $payload))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrors($field);

    $fresh = $user->fresh();
    expect($fresh->name)->toBe('Original Name')
        ->and($fresh->email)->toBe('original@example.com')
        ->and($fresh->email_verified_at?->toDateTimeString())->toBe($verifiedAt?->toDateTimeString());
})->with([
    'missing name' => [['name' => ''], 'name'],
    'malformed email' => [['email' => 'not-an-email'], 'email'],
    'uppercase email' => [['email' => 'New@Example.com'], 'email'],
    'email taken by another user' => [['email' => 'taken@example.com'], 'email'],
    'missing email' => [['email' => ''], 'email'],
]);

test('changing the email sends a new verification email', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => $user->name, 'email' => 'new-address@example.com'])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->email_verified_at)->toBeNull();
    Notification::assertSentTo($user, VerifyEmail::class);
});

test('saving the profile without changing the email sends no verification email', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => 'Renamed', 'email' => $user->email])
        ->assertSessionHasNoErrors();

    Notification::assertNothingSent();
});
