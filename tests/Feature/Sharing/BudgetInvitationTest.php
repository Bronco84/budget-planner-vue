<?php

use App\Enums\BudgetRole;
use App\Mail\BudgetInvitationMail;
use App\Models\Budget;
use App\Models\BudgetInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * SHARE-001: an owner invites someone by email; an existing user accepts while
 * signed in, an unknown email registers through the invitation link.
 */

beforeEach(function () {
    Mail::fake();
    $this->owner = User::factory()->create(['name' => 'Olive Owner']);
    $this->budget = Budget::factory()->create(['user_id' => $this->owner->id, 'name' => 'Household']);
});

/**
 * Invite through the HTTP endpoint and capture the plaintext token from the
 * emailed accept link.
 */
function inviteToBudget(Budget $budget, User $owner, string $email, string $role = 'editor'): string
{
    test()->actingAs($owner)
        ->post(route('budgets.invitations.store', $budget), ['email' => $email, 'role' => $role])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('message', 'Invitation sent to '.strtolower($email).'.');

    $token = null;
    Mail::assertSent(BudgetInvitationMail::class, function (BudgetInvitationMail $mail) use ($email, &$token) {
        if (! $mail->hasTo(strtolower($email))) {
            return false;
        }
        $token = basename(parse_url($mail->acceptUrl, PHP_URL_PATH));

        return true;
    });

    return $token;
}

test('an owner invites an existing user who is emailed a link and accepts while signed in', function () {
    $invitee = User::factory()->create(['email' => 'erin@example.com']);

    $token = inviteToBudget($this->budget, $this->owner, 'Erin@Example.com', 'editor');

    $invitation = BudgetInvitation::sole();
    expect($invitation)
        ->email->toBe('erin@example.com')
        ->role->toBe(BudgetRole::Editor)
        ->invited_by->toBe($this->owner->id)
        ->accepted_at->toBeNull()
        ->and($invitation->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()))->toBeTrue()
        ->and($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->token_hash)->not->toBe($token);

    $this->actingAs($invitee)
        ->get(route('invitations.show', $token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Invitations/Show')
            ->where('status', 'ready')
            ->where('invitation.budget_name', 'Household')
            ->where('invitation.role', 'editor')
            ->where('invitation.invited_by', 'Olive Owner'));

    $this->actingAs($invitee)
        ->post(route('invitations.accept', $token))
        ->assertRedirect(route('budgets.show', $this->budget));

    expect($this->budget->roleFor($invitee))->toBe(BudgetRole::Editor)
        ->and($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and($invitee->fresh()->getActiveBudget()->id)->toBe($this->budget->id);

    $this->actingAs($invitee)->get(route('budgets.show', $this->budget))->assertOk();
});

test('the invitation email tells an existing user to sign in and an unknown email to create an account', function () {
    User::factory()->create(['email' => 'known@example.com']);

    inviteToBudget($this->budget, $this->owner, 'known@example.com', 'viewer');
    inviteToBudget($this->budget, $this->owner, 'new@example.com', 'viewer');

    Mail::assertSent(BudgetInvitationMail::class, fn ($mail) => $mail->hasTo('known@example.com') && $mail->hasAccount === true
        && str_contains($mail->render(), 'Sign in with this email address'));
    Mail::assertSent(BudgetInvitationMail::class, fn ($mail) => $mail->hasTo('new@example.com') && $mail->hasAccount === false
        && str_contains($mail->render(), 'Create your account'));
});

test('markdown in a budget or inviter name is shown as text in the invitation email, not as a link', function () {
    $this->owner->update(['name' => 'Olive [Reset your password](https://evil.example/reset)']);
    $this->budget->update(['name' => 'Family [Verify your bank login](https://evil.example/login)']);

    inviteToBudget($this->budget, $this->owner, 'erin@example.com', 'viewer');

    Mail::assertSent(BudgetInvitationMail::class, function (BudgetInvitationMail $mail) {
        $html = $mail->render();

        expect($html)
            ->not->toContain('href="https://evil.example')
            ->toContain('Family [Verify your bank login](https://evil.example/login)')
            ->toContain('Olive [Reset your password](https://evil.example/reset)');

        return true;
    });
});

test('accepting while signed in as a different email is rejected', function () {
    $token = inviteToBudget($this->budget, $this->owner, 'erin@example.com');
    $intruder = User::factory()->create(['email' => 'mallory@example.com']);

    $this->actingAs($intruder)
        ->get(route('invitations.show', $token))
        ->assertInertia(fn (Assert $page) => $page->where('status', 'wrong_account'));

    $this->actingAs($intruder)
        ->from(route('invitations.show', $token))
        ->post(route('invitations.accept', $token))
        ->assertSessionHasErrors('invitation');

    expect($this->budget->roleFor($intruder))->toBeNull()
        ->and(BudgetInvitation::sole()->accepted_at)->toBeNull();
});

test('expired, used and tampered invitation tokens are rejected', function (string $case) {
    $invitee = User::factory()->create(['email' => 'erin@example.com']);
    $token = inviteToBudget($this->budget, $this->owner, 'erin@example.com');

    match ($case) {
        'expired' => BudgetInvitation::query()->update(['expires_at' => now()->subMinute()]),
        'used' => BudgetInvitation::query()->update(['accepted_at' => now()]),
        'tampered' => $token = substr($token, 0, -1).(str_ends_with($token, 'a') ? 'b' : 'a'),
    };

    $this->actingAs($invitee)
        ->get(route('invitations.show', $token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('status', 'invalid')->where('invitation', null));

    $this->actingAs($invitee)
        ->post(route('invitations.accept', $token))
        ->assertRedirect(route('invitations.show', $token))
        ->assertSessionHas('error');

    expect($this->budget->roleFor($invitee))->toBeNull();
})->with(['expired', 'used', 'tampered']);

test('an invitation can only be accepted once', function () {
    $invitee = User::factory()->create(['email' => 'erin@example.com']);
    $token = inviteToBudget($this->budget, $this->owner, 'erin@example.com', 'viewer');

    $this->actingAs($invitee)->post(route('invitations.accept', $token))->assertRedirect(route('budgets.show', $this->budget));
    $this->budget->connected_users()->detach($invitee->id);

    $this->actingAs($invitee)->post(route('invitations.accept', $token))->assertSessionHas('error');

    expect($this->budget->roleFor($invitee))->toBeNull();
});

test('a guest opening an invitation is asked to sign in or register and returned to it after signing in', function () {
    User::factory()->create(['email' => 'known@example.com']);
    $knownToken = inviteToBudget($this->budget, $this->owner, 'known@example.com');
    $newToken = inviteToBudget($this->budget, $this->owner, 'new@example.com');
    auth()->logout();

    $this->get(route('invitations.show', $newToken))
        ->assertInertia(fn (Assert $page) => $page->where('status', 'register')->where('token', $newToken));

    $this->get(route('invitations.show', $knownToken))
        ->assertInertia(fn (Assert $page) => $page->where('status', 'sign_in'));

    $this->actingAs(User::where('email', 'known@example.com')->first())
        ->get(route('budgets.index'))
        ->assertRedirect(route('invitations.show', $knownToken));
});

test('inviting someone who is already a member is rejected and no email is sent', function () {
    $member = User::factory()->create(['email' => 'member@example.com']);
    $this->budget->connected_users()->attach($member->id, ['access_level' => 'viewer']);

    $this->actingAs($this->owner)
        ->post(route('budgets.invitations.store', $this->budget), ['email' => 'member@example.com', 'role' => 'editor'])
        ->assertSessionHasErrors('email');

    $this->actingAs($this->owner)
        ->post(route('budgets.invitations.store', $this->budget), ['email' => $this->owner->email, 'role' => 'editor'])
        ->assertSessionHasErrors('email');

    expect(BudgetInvitation::count())->toBe(0);
    Mail::assertNothingSent();
});

test('invitations require a valid email and role', function () {
    $this->actingAs($this->owner)
        ->post(route('budgets.invitations.store', $this->budget), ['email' => 'not-an-email', 'role' => 'admin'])
        ->assertSessionHasErrors(['email', 'role']);

    expect(BudgetInvitation::count())->toBe(0);
});

test('an invitation email with a CRLF header injection is rejected and nothing is sent', function () {
    $this->actingAs($this->owner)
        ->post(route('budgets.invitations.store', $this->budget), [
            'email' => "\"a\r\nBcc: victim@example.com\"@example.com",
            'role' => 'editor',
        ])
        ->assertSessionHasErrors('email');

    expect(BudgetInvitation::count())->toBe(0);
    Mail::assertNothingSent();
});

test('re-inviting the same email replaces the pending invitation', function () {
    $first = inviteToBudget($this->budget, $this->owner, 'erin@example.com', 'viewer');
    $second = inviteToBudget($this->budget, $this->owner, 'erin@example.com', 'editor');

    expect(BudgetInvitation::sole()->role)->toBe(BudgetRole::Editor)
        ->and(BudgetInvitation::findPendingByToken($first))->toBeNull()
        ->and(BudgetInvitation::findPendingByToken($second))->not->toBeNull();
});

test('an owner sees and revokes pending invitations', function () {
    $token = inviteToBudget($this->budget, $this->owner, 'erin@example.com', 'viewer');
    $invitation = BudgetInvitation::sole();

    $this->actingAs($this->owner)
        ->get(route('budgets.sharing.index', $this->budget))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Budgets/Sharing')
            ->has('invitations', 1)
            ->where('invitations.0.email', 'erin@example.com')
            ->missing('invitations.0.token_hash'));

    $this->actingAs($this->owner)
        ->delete(route('budgets.invitations.destroy', [$this->budget, $invitation]))
        ->assertSessionHas('message');

    expect(BudgetInvitation::count())->toBe(0)
        ->and(BudgetInvitation::findPendingByToken($token))->toBeNull();
});

test('a failed invitation email is reported without losing the invitation', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

    $this->actingAs($this->owner)
        ->post(route('budgets.invitations.store', $this->budget), ['email' => 'erin@example.com', 'role' => 'viewer'])
        ->assertSessionHas('error', 'The invitation was created, but the email could not be sent. Try again later.');

    expect(BudgetInvitation::count())->toBe(1);
});

test('sending invitations is rate limited', function () {
    foreach (range(1, 10) as $i) {
        $this->actingAs($this->owner)
            ->post(route('budgets.invitations.store', $this->budget), ['email' => "person{$i}@example.com", 'role' => 'viewer'])
            ->assertRedirect();
    }

    $this->actingAs($this->owner)
        ->post(route('budgets.invitations.store', $this->budget), ['email' => 'one-too-many@example.com', 'role' => 'viewer'])
        ->assertStatus(429);

    expect(BudgetInvitation::count())->toBe(10);
});

test('editors and viewers cannot invite or revoke invitations', function (string $role) {
    $member = User::factory()->create();
    $this->budget->connected_users()->attach($member->id, ['access_level' => $role]);
    $invitation = $this->budget->invitations()->create([
        'email' => 'pending@example.com',
        'role' => BudgetRole::Viewer,
        'token_hash' => BudgetInvitation::hashToken('pending-token'),
        'invited_by' => $this->owner->id,
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($member)
        ->post(route('budgets.invitations.store', $this->budget), ['email' => 'friend@example.com', 'role' => 'owner'])
        ->assertForbidden();
    $this->actingAs($member)
        ->delete(route('budgets.invitations.destroy', [$this->budget, $invitation]))
        ->assertForbidden();
    $this->actingAs($member)
        ->get(route('budgets.sharing.index', $this->budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('invitations', [])->where('can.manage', false));

    expect(BudgetInvitation::count())->toBe(1);
    Mail::assertNothingSent();
})->with(['editor', 'viewer']);

test('an owner member who is removed, demoted or leaves loses the invitations they issued', function (string $how) {
    $member = User::factory()->create();
    $this->budget->connected_users()->attach($member->id, ['access_level' => 'owner']);
    $token = inviteToBudget($this->budget, $member, 'alt@example.com', 'owner');
    $alt = User::factory()->create(['email' => 'alt@example.com']);

    match ($how) {
        'removed' => $this->actingAs($this->owner)
            ->delete(route('budgets.members.destroy', [$this->budget, $member]))
            ->assertSessionHasNoErrors(),
        'demoted' => $this->actingAs($this->owner)
            ->patch(route('budgets.members.update', [$this->budget, $member]), ['role' => 'editor'])
            ->assertSessionHasNoErrors(),
        'left' => $this->actingAs($member)
            ->delete(route('budgets.membership.leave', $this->budget))
            ->assertSessionHasNoErrors(),
    };

    expect(BudgetInvitation::count())->toBe(0);

    $this->actingAs($alt)->post(route('invitations.accept', $token))->assertSessionHas('error');
    expect($this->budget->roleFor($alt))->toBeNull();
})->with(['removed', 'demoted', 'left']);

test('a primary owner who leaves loses the invitations they issued', function () {
    $successor = User::factory()->create();
    $this->budget->connected_users()->attach($successor->id, ['access_level' => 'owner']);
    inviteToBudget($this->budget, $this->owner, 'alt@example.com', 'owner');
    inviteToBudget($this->budget, $successor, 'friend@example.com', 'viewer');

    $this->actingAs($this->owner)
        ->delete(route('budgets.membership.leave', $this->budget))
        ->assertSessionHasNoErrors();

    expect(BudgetInvitation::pluck('email')->all())->toBe(['friend@example.com']);
});

test('re-saving an owner member as owner keeps the invitations they issued', function () {
    $member = User::factory()->create();
    $this->budget->connected_users()->attach($member->id, ['access_level' => 'owner']);
    inviteToBudget($this->budget, $member, 'friend@example.com', 'viewer');

    $this->actingAs($this->owner)
        ->patch(route('budgets.members.update', [$this->budget, $member]), ['role' => 'owner'])
        ->assertSessionHasNoErrors();

    expect(BudgetInvitation::count())->toBe(1);
});

test('an invitation whose inviter no longer owns the budget cannot be accepted', function () {
    $member = User::factory()->create();
    $this->budget->connected_users()->attach($member->id, ['access_level' => 'owner']);
    $token = inviteToBudget($this->budget, $member, 'alt@example.com', 'owner');
    $alt = User::factory()->create(['email' => 'alt@example.com']);

    // Demoted by a path that leaves their invitations in place.
    $this->budget->connected_users()->updateExistingPivot($member->id, ['access_level' => 'viewer']);

    $this->actingAs($alt)->post(route('invitations.accept', $token))->assertSessionHasErrors('invitation');

    expect($this->budget->roleFor($alt))->toBeNull()
        ->and(BudgetInvitation::sole()->accepted_at)->toBeNull();
});

test('an invitation whose inviter was deleted cannot be accepted', function () {
    $member = User::factory()->create();
    $this->budget->connected_users()->attach($member->id, ['access_level' => 'owner']);
    $token = inviteToBudget($this->budget, $member, 'alt@example.com', 'editor');
    $alt = User::factory()->create(['email' => 'alt@example.com']);

    $this->budget->connected_users()->detach($member->id);
    $member->delete();

    $this->actingAs($alt)->post(route('invitations.accept', $token))->assertSessionHasErrors('invitation');
    expect($this->budget->roleFor($alt))->toBeNull();
});
