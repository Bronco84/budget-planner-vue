<?php

use App\Models\Budget;
use App\Models\BudgetInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * SHARE-001 / SHARE-002 / SHARE-003. Sharing actions flash their outcome under
 * the shared flash.message / flash.error keys, and the flash reaches the page
 * the browser lands on (the Sharing page toasts it).
 */

beforeEach(function () {
    Mail::fake();
    $this->owner = User::factory()->create(['name' => 'Olive Owner']);
    $this->budget = Budget::factory()->create(['user_id' => $this->owner->id, 'name' => 'Household']);
    $this->sharingUrl = route('budgets.sharing.index', $this->budget);
});

function sharingMember(Budget $budget, string $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $budget->connected_users()->attach($user->id, ['access_level' => $role]);

    return $user;
}

function assertLandsWithFlash($response, string $component, string $key, string $message): void
{
    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component($component)
        ->where("flash.{$key}", $message));
}

test('sending an invitation flashes a confirmation on the sharing page', function () {
    $response = $this->actingAs($this->owner)->from($this->sharingUrl)->followingRedirects()
        ->post(route('budgets.invitations.store', $this->budget), ['email' => 'erin@example.com', 'role' => 'viewer']);

    assertLandsWithFlash($response, 'Budgets/Sharing', 'message', 'Invitation sent to erin@example.com.');
});

test('a failed invitation email flashes an error on the sharing page', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

    $response = $this->actingAs($this->owner)->from($this->sharingUrl)->followingRedirects()
        ->post(route('budgets.invitations.store', $this->budget), ['email' => 'erin@example.com', 'role' => 'viewer']);

    assertLandsWithFlash($response, 'Budgets/Sharing', 'error', 'The invitation was created, but the email could not be sent. Try again later.');
});

test('revoking an invitation flashes a confirmation on the sharing page', function () {
    $this->actingAs($this->owner)
        ->post(route('budgets.invitations.store', $this->budget), ['email' => 'erin@example.com', 'role' => 'viewer']);
    $invitation = BudgetInvitation::sole();

    $response = $this->actingAs($this->owner)->from($this->sharingUrl)->followingRedirects()
        ->delete(route('budgets.invitations.destroy', [$this->budget, $invitation]));

    assertLandsWithFlash($response, 'Budgets/Sharing', 'message', 'Invitation for erin@example.com revoked.');
});

test('changing a member role flashes a confirmation on the sharing page', function () {
    $member = sharingMember($this->budget, 'viewer', ['name' => 'Vera Viewer']);

    $response = $this->actingAs($this->owner)->from($this->sharingUrl)->followingRedirects()
        ->patch(route('budgets.members.update', [$this->budget, $member]), ['role' => 'editor']);

    assertLandsWithFlash($response, 'Budgets/Sharing', 'message', 'Vera Viewer is now an editor.');
});

test('removing a member flashes a confirmation on the sharing page', function () {
    $member = sharingMember($this->budget, 'editor', ['name' => 'Eddie Editor']);

    $response = $this->actingAs($this->owner)->from($this->sharingUrl)->followingRedirects()
        ->delete(route('budgets.members.destroy', [$this->budget, $member]));

    assertLandsWithFlash($response, 'Budgets/Sharing', 'message', 'Eddie Editor no longer has access to this budget.');
});

test('leaving a budget keeps the confirmation through the budgets index redirect', function () {
    // budgets.index only redirects (to the active budget or the create page),
    // so it must keep the flash for the page the member actually lands on.
    $member = sharingMember($this->budget, 'viewer');
    $own = Budget::factory()->create(['user_id' => $member->id]);

    $response = $this->actingAs($member)->from($this->sharingUrl)->followingRedirects()
        ->delete(route('budgets.membership.leave', $this->budget));

    assertLandsWithFlash($response, 'Budgets/Show', 'message', 'You left the budget "Household".');
    $response->assertInertia(fn (Assert $page) => $page->where('budget.id', $own->id));
});

test('leaving the only budget you can access keeps the confirmation on the create page', function () {
    $member = sharingMember($this->budget, 'viewer');

    $response = $this->actingAs($member)->from($this->sharingUrl)->followingRedirects()
        ->delete(route('budgets.membership.leave', $this->budget));

    assertLandsWithFlash($response, 'Budgets/Create', 'message', 'You left the budget "Household".');
});
