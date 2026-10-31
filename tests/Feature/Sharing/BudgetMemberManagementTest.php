<?php

use App\Enums\BudgetRole;
use App\Models\Budget;
use App\Models\User;
use App\Models\UserPreference;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * SHARE-002 (manage members and roles) and SHARE-003 (leave a budget,
 * ownership transfer). The primary owner (budgets.user_id) is always an owner,
 * so a budget can never be left without one.
 */

beforeEach(function () {
    $this->owner = User::factory()->create(['name' => 'Olive Owner']);
    $this->budget = Budget::factory()->create(['user_id' => $this->owner->id, 'name' => 'Household']);
});

function addBudgetMember(Budget $budget, string $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $budget->connected_users()->attach($user->id, ['access_level' => $role]);

    return $user;
}

test('the sharing page lists the primary owner first and every member with their role', function () {
    $editor = addBudgetMember($this->budget, 'editor', ['name' => 'Eddie Editor']);
    $viewer = addBudgetMember($this->budget, 'viewer', ['name' => 'Vera Viewer']);

    $this->actingAs($viewer)
        ->get(route('budgets.sharing.index', $this->budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Budgets/Sharing')
            ->where('role', 'viewer')
            ->where('can.manage', false)
            ->has('members', 3)
            ->where('members.0.name', 'Olive Owner')
            ->where('members.0.is_primary_owner', true)
            ->where('members.1.name', 'Eddie Editor')
            ->where('members.1.role', 'editor')
            ->where('members.2.is_you', true)
            ->where('breadcrumbs.1.title', 'Sharing'));
});

test('an owner changes a member role, including promoting them to owner', function () {
    $member = addBudgetMember($this->budget, 'viewer');

    $this->actingAs($this->owner)
        ->patch(route('budgets.members.update', [$this->budget, $member]), ['role' => 'editor'])
        ->assertSessionHasNoErrors();
    expect($this->budget->roleFor($member))->toBe(BudgetRole::Editor);

    $this->actingAs($this->owner)
        ->patch(route('budgets.members.update', [$this->budget, $member]), ['role' => 'owner'])
        ->assertSessionHasNoErrors();
    expect($this->budget->roleFor($member))->toBe(BudgetRole::Owner);

    // A promoted owner can now manage members too.
    $other = addBudgetMember($this->budget, 'editor');
    $this->actingAs($member)
        ->patch(route('budgets.members.update', [$this->budget, $other]), ['role' => 'viewer'])
        ->assertSessionHasNoErrors();
    expect($this->budget->roleFor($other))->toBe(BudgetRole::Viewer);
});

test('an invalid role is rejected', function () {
    $member = addBudgetMember($this->budget, 'viewer');

    $this->actingAs($this->owner)
        ->patch(route('budgets.members.update', [$this->budget, $member]), ['role' => 'admin'])
        ->assertSessionHasErrors('role');

    expect($this->budget->roleFor($member))->toBe(BudgetRole::Viewer);
});

test('an owner removes a member, whose stale active budget then falls back', function () {
    $member = addBudgetMember($this->budget, 'editor');
    $ownBudget = Budget::factory()->create(['user_id' => $member->id]);
    $member->setActiveBudget($this->budget->id);

    $this->actingAs($this->owner)
        ->delete(route('budgets.members.destroy', [$this->budget, $member]))
        ->assertSessionHas('message');

    expect($this->budget->roleFor($member))->toBeNull();

    $this->actingAs($member)->get(route('budgets.show', $this->budget))->assertForbidden();
    $this->actingAs($member)->get(route('budgets.index'))->assertRedirect(route('budgets.show', $ownBudget));
    expect(UserPreference::getActiveBudgetId($member->id))->toBeNull();
});

test('the primary owner cannot be removed, downgraded or reached through member routes', function () {
    $coOwner = addBudgetMember($this->budget, 'owner');

    $this->actingAs($coOwner)
        ->patch(route('budgets.members.update', [$this->budget, $this->owner]), ['role' => 'viewer'])
        ->assertNotFound();
    $this->actingAs($coOwner)
        ->delete(route('budgets.members.destroy', [$this->budget, $this->owner]))
        ->assertNotFound();

    expect($this->budget->fresh()->user_id)->toBe($this->owner->id)
        ->and($this->budget->roleFor($this->owner))->toBe(BudgetRole::Owner);
});

test('a non-member cannot be changed through member routes', function () {
    $stranger = User::factory()->create();

    $this->actingAs($this->owner)
        ->delete(route('budgets.members.destroy', [$this->budget, $stranger]))
        ->assertNotFound();
});

test('editors and viewers cannot change roles or remove members', function (string $role) {
    $actor = addBudgetMember($this->budget, $role);
    $target = addBudgetMember($this->budget, 'viewer');

    $this->actingAs($actor)
        ->patch(route('budgets.members.update', [$this->budget, $target]), ['role' => 'owner'])
        ->assertForbidden();
    $this->actingAs($actor)
        ->patch(route('budgets.members.update', [$this->budget, $actor]), ['role' => 'owner'])
        ->assertForbidden();
    $this->actingAs($actor)
        ->delete(route('budgets.members.destroy', [$this->budget, $target]))
        ->assertForbidden();

    expect($this->budget->roleFor($target))->toBe(BudgetRole::Viewer)
        ->and($this->budget->roleFor($actor))->toBe(BudgetRole::from($role));
})->with(['editor', 'viewer']);

test('a member leaves a budget', function (string $role) {
    $member = addBudgetMember($this->budget, $role);

    $this->actingAs($member)
        ->delete(route('budgets.membership.leave', $this->budget))
        ->assertRedirect(route('budgets.index'));

    expect($this->budget->roleFor($member))->toBeNull();
})->with(['viewer', 'editor', 'owner']);

test('the last owner cannot leave the budget', function () {
    addBudgetMember($this->budget, 'editor');

    $this->actingAs($this->owner)
        ->from(route('budgets.sharing.index', $this->budget))
        ->delete(route('budgets.membership.leave', $this->budget))
        ->assertSessionHasErrors('membership');

    expect($this->budget->fresh()->user_id)->toBe($this->owner->id);
});

test('a primary owner who leaves hands primary ownership to the longest-standing owner', function () {
    $first = addBudgetMember($this->budget, 'owner');
    $this->travel(1)->minute();
    addBudgetMember($this->budget, 'owner');

    $this->actingAs($this->owner)
        ->delete(route('budgets.membership.leave', $this->budget))
        ->assertRedirect(route('budgets.index'));

    $budget = $this->budget->fresh();
    expect($budget->user_id)->toBe($first->id)
        ->and($budget->roleFor($this->owner))->toBeNull()
        ->and($budget->connected_users()->where('users.id', $first->id)->exists())->toBeFalse();
});

test('a stranger cannot leave or view the sharing page', function () {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('budgets.sharing.index', $this->budget))->assertForbidden();
    $this->actingAs($stranger)->delete(route('budgets.membership.leave', $this->budget))->assertForbidden();
});

test('the primary owner transfers primary ownership and stays on as an owner', function () {
    $editor = addBudgetMember($this->budget, 'editor');

    $this->actingAs($this->owner)
        ->post(route('budgets.ownership.transfer', $this->budget), ['user_id' => $editor->id])
        ->assertSessionHasNoErrors();

    $budget = $this->budget->fresh();
    expect($budget->user_id)->toBe($editor->id)
        ->and($budget->roleFor($editor))->toBe(BudgetRole::Owner)
        ->and($budget->roleFor($this->owner))->toBe(BudgetRole::Owner)
        ->and($budget->isPrimaryOwner($this->owner))->toBeFalse();

    // The new primary owner can now downgrade the previous one.
    $this->actingAs($editor)
        ->patch(route('budgets.members.update', [$budget, $this->owner]), ['role' => 'viewer'])
        ->assertSessionHasNoErrors();
    expect($budget->roleFor($this->owner))->toBe(BudgetRole::Viewer);
});

test('ownership can only be transferred by the primary owner to a current member', function () {
    $coOwner = addBudgetMember($this->budget, 'owner');
    $stranger = User::factory()->create();

    $this->actingAs($coOwner)
        ->post(route('budgets.ownership.transfer', $this->budget), ['user_id' => $coOwner->id])
        ->assertForbidden();

    $this->actingAs($this->owner)
        ->post(route('budgets.ownership.transfer', $this->budget), ['user_id' => $stranger->id])
        ->assertSessionHasErrors('user_id');

    expect($this->budget->fresh()->user_id)->toBe($this->owner->id);
});

test('shared budgets appear in the switcher with the member role and become the active budget', function () {
    $viewer = addBudgetMember($this->budget, 'viewer');
    $own = Budget::factory()->create(['user_id' => $viewer->id, 'name' => 'Mine']);

    $this->actingAs($viewer)
        ->postJson(route('preferences.active-budget.set'), ['budget_id' => $this->budget->id])
        ->assertOk();

    $this->actingAs($viewer)
        ->get(route('budgets.index'))
        ->assertRedirect(route('budgets.show', $this->budget));

    $this->actingAs($viewer)
        ->get(route('budgets.show', $this->budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('activeBudget.id', $this->budget->id)
            ->where('activeBudget.role', 'viewer')
            ->where('budgetAccess.role', 'viewer')
            ->where('budgetAccess.can.edit', false)
            ->where('budgetAccess.can.manage', false)
            ->where('userBudgets', fn ($budgets) => collect($budgets)->firstWhere('id', $this->budget->id)['role'] === 'viewer'
                && collect($budgets)->firstWhere('id', $this->budget->id)['shared'] === true
                && collect($budgets)->firstWhere('id', $own->id)['role'] === 'owner'));
});

test('editors see edit controls but not owner controls', function () {
    $editor = addBudgetMember($this->budget, 'editor');

    $this->actingAs($editor)
        ->get(route('budgets.show', $this->budget))
        ->assertInertia(fn (Assert $page) => $page
            ->where('budgetAccess.can.edit', true)
            ->where('budgetAccess.can.manage', false)
            ->where('budgetAccess.can.manage_connections', false));
});

test('a legacy admin access level is treated as owner', function () {
    $legacy = addBudgetMember($this->budget, 'admin');

    expect($this->budget->roleFor($legacy))->toBe(BudgetRole::Owner)
        ->and($legacy->can('manageMembers', $this->budget))->toBeTrue();
});
