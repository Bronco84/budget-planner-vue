<?php

use App\Enums\BudgetRole;
use App\Models\Budget;
use App\Models\BudgetInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * AUTH-002 / SHARE-001: open registration is closed. An account can be created
 * only through a valid budget invitation (single use, expiring, same email) or
 * for an email on config('auth.registration_allowlist').
 */

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->owner->id, 'name' => 'Shared house']);
});

function registrationInvitation(Budget $budget, User $owner, string $email = 'newcomer@example.com', string $role = 'editor', array $overrides = []): string
{
    $token = BudgetInvitation::generateToken();
    $budget->invitations()->create(array_merge([
        'email' => $email,
        'role' => $role,
        'token_hash' => BudgetInvitation::hashToken($token),
        'invited_by' => $owner->id,
        'expires_at' => now()->addDays(BudgetInvitation::EXPIRY_DAYS),
    ], $overrides));

    return $token;
}

test('an invited unknown email registers through the invitation and joins the budget with the invited role', function () {
    $token = registrationInvitation($this->budget, $this->owner, 'newcomer@example.com', 'editor');

    $this->get(route('register', ['invitation' => $token]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Register')
            ->where('invitation.email', 'newcomer@example.com')
            ->where('invitation.budget_name', 'Shared house')
            ->where('invitation.role', 'editor')
            ->where('invitationError', null));

    $this->post(route('register'), ['name' => 'Newcomer', 'email' => 'Newcomer@example.com', 'invitation' => $token])
        ->assertRedirect(route('passkey.register', ['new_user' => true]));

    $user = User::where('email', 'Newcomer@example.com')->sole();
    $this->assertAuthenticatedAs($user);

    expect($this->budget->roleFor($user))->toBe(BudgetRole::Editor)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(BudgetInvitation::sole()->accepted_at)->not->toBeNull()
        ->and($user->getActiveBudget()->id)->toBe($this->budget->id);
});

test('registering with an invitation for a different email is rejected', function () {
    $token = registrationInvitation($this->budget, $this->owner, 'newcomer@example.com');

    $this->from(route('register'))
        ->post(route('register'), ['name' => 'Other', 'email' => 'other@example.com', 'invitation' => $token])
        ->assertSessionHasErrors(['email' => 'Use the email address the invitation was sent to.']);

    expect(User::where('email', 'other@example.com')->exists())->toBeFalse()
        ->and(BudgetInvitation::sole()->accepted_at)->toBeNull();
    $this->assertGuest();
});

test('expired, used and tampered invitation tokens cannot be used to register', function (string $case) {
    $token = registrationInvitation($this->budget, $this->owner, 'newcomer@example.com', 'viewer', match ($case) {
        'expired' => ['expires_at' => now()->subSecond()],
        'used' => ['accepted_at' => now()->subDay()],
        default => [],
    });
    if ($case === 'tampered') {
        $token = strrev($token);
    }

    $this->get(route('register', ['invitation' => $token]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('invitation', null)
            ->where('invitationError', 'This invitation link is invalid, expired or has already been used.'));

    $this->from(route('register'))
        ->post(route('register'), ['name' => 'Newcomer', 'email' => 'newcomer@example.com', 'invitation' => $token])
        ->assertSessionHasErrors('email');

    expect(User::where('email', 'newcomer@example.com')->exists())->toBeFalse();
    $this->assertGuest();
})->with(['expired', 'used', 'tampered']);

test('an invitation token registers only one account', function () {
    $token = registrationInvitation($this->budget, $this->owner, 'newcomer@example.com');

    $this->post(route('register'), ['name' => 'Newcomer', 'email' => 'newcomer@example.com', 'invitation' => $token])
        ->assertRedirect(route('passkey.register', ['new_user' => true]));
    auth()->logout();
    User::where('email', 'newcomer@example.com')->delete();

    $this->from(route('register'))
        ->post(route('register'), ['name' => 'Again', 'email' => 'newcomer@example.com', 'invitation' => $token])
        ->assertSessionHasErrors('email');

    expect(User::where('email', 'newcomer@example.com')->exists())->toBeFalse();
});

test('open registration stays closed without an invitation', function () {
    $this->from(route('register'))
        ->post(route('register'), ['name' => 'Stranger', 'email' => 'stranger@example.com'])
        ->assertSessionHasErrors(['email' => 'Registration is currently restricted. Please contact the administrator.']);

    expect(User::where('email', 'stranger@example.com')->exists())->toBeFalse();
    $this->assertGuest();
});

test('an email on the configured registration allowlist registers without an invitation', function () {
    config(['auth.registration_allowlist' => ['allowed@example.com']]);

    $this->post(route('register'), ['name' => 'Allowed', 'email' => 'Allowed@Example.com'])
        ->assertRedirect(route('passkey.register', ['new_user' => true]));

    expect(User::where('email', 'Allowed@Example.com')->exists())->toBeTrue();
});

test('the registration allowlist defaults to the owner email', function () {
    expect(config('auth.registration_allowlist'))->toContain('bamccoley@gmail.com');
});
