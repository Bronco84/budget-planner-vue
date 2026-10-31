<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;

/*
 * ACCOUNT-002: updating, logo actions and deletion of an account.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create([
        'budget_id' => $this->budget->id,
        'name' => 'Chase Checking',
        'include_in_budget' => true,
        'exclude_from_total_balance' => false,
    ]);
});

test('a valid update persists name type and budget inclusion and redirects to the dashboard', function () {
    $this->actingAs($this->user)
        ->patch(route('budgets.accounts.update', [$this->budget, $this->account]), [
            'name' => 'Rainy Day',
            'type' => 'savings',
            'include_in_budget' => false,
            'logo_url' => 'https://example.com/logo.png',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('budgets.show', $this->budget));

    $account = $this->account->fresh();
    expect($account->name)->toBe('Rainy Day')
        ->and($account->type)->toBe('savings')
        ->and((bool) $account->include_in_budget)->toBeFalse()
        ->and((bool) $account->exclude_from_total_balance)->toBeTrue()
        ->and($account->logo_url)->toBe('https://example.com/logo.png');
});

test('an update missing name and type or with a non URL logo is rejected and nothing changes', function () {
    $this->actingAs($this->user)
        ->patch(route('budgets.accounts.update', [$this->budget, $this->account]), [
            'name' => '',
            'type' => '',
            'logo_url' => 'not a url',
        ])
        ->assertSessionHasErrors(['name', 'type', 'logo_url']);

    $account = $this->account->fresh();
    expect($account->name)->toBe('Chase Checking')
        ->and($account->type)->toBe('checking')
        ->and($account->logo_url)->toBeNull();
});

test('fetch logo stores a favicon URL for a mapped institution and clears the custom logo', function () {
    $this->account->update(['custom_logo' => 'data:image/png;base64,iVBORw0KGgo=']);

    $this->actingAs($this->user)
        ->from(route('budgets.accounts.edit', [$this->budget, $this->account]))
        ->post(route('accounts.fetchLogo', [$this->budget, $this->account]))
        ->assertRedirect(route('budgets.accounts.edit', [$this->budget, $this->account]))
        ->assertSessionHas('message', 'Logo fetched successfully!');

    $account = $this->account->fresh();
    expect($account->logo_url)->toContain('chase.com')
        ->and($account->custom_logo)->toBeNull();
});

test('clear logo removes both logo fields', function () {
    $this->account->update([
        'logo_url' => 'https://example.com/logo.png',
        'custom_logo' => 'data:image/png;base64,iVBORw0KGgo=',
    ]);

    $this->actingAs($this->user)
        ->delete(route('accounts.clearLogo', [$this->budget, $this->account]))
        ->assertSessionHas('message', 'Logo removed.');

    $account = $this->account->fresh();
    expect($account->logo_url)->toBeNull()
        ->and($account->custom_logo)->toBeNull();
});

test('fetch logo for an unmapped institution flashes an error and leaves the logo unchanged', function () {
    $this->account->update(['name' => 'Zqxv Credit Union', 'logo_url' => 'https://example.com/old.png']);

    $this->actingAs($this->user)
        ->post(route('accounts.fetchLogo', [$this->budget, $this->account]))
        ->assertSessionHas('error', 'Could not find a logo for this institution. Try uploading a custom logo instead.');

    expect($this->account->fresh()->logo_url)->toBe('https://example.com/old.png');
});

test('an account with no transactions is deleted and the owner returns to the dashboard', function () {
    $this->actingAs($this->user)
        ->delete(route('budgets.accounts.destroy', [$this->budget, $this->account]))
        ->assertRedirect(route('budgets.show', $this->budget))
        ->assertSessionHas('message', 'Account deleted successfully');

    expect(Account::find($this->account->id))->toBeNull();
});

test('deleting an account that has transactions is refused and the account remains', function () {
    Transaction::factory()->create(['budget_id' => $this->budget->id, 'account_id' => $this->account->id]);

    $this->actingAs($this->user)
        ->delete(route('budgets.accounts.destroy', [$this->budget, $this->account]))
        ->assertSessionHas('error', 'Cannot delete account with existing transactions.');

    expect(Account::find($this->account->id))->not->toBeNull();
});

test('fetch logo and clear logo on another users account are forbidden and nothing changes', function () {
    $intruder = User::factory()->create();
    $this->account->update(['logo_url' => 'https://example.com/keep.png']);

    $this->actingAs($intruder)
        ->post(route('accounts.fetchLogo', [$this->budget, $this->account]))
        ->assertForbidden();

    $this->actingAs($intruder)
        ->delete(route('accounts.clearLogo', [$this->budget, $this->account]))
        ->assertForbidden();

    expect($this->account->fresh()->logo_url)->toBe('https://example.com/keep.png');
});
