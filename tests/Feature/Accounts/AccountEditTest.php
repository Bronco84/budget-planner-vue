<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\Transfer;
use App\Models\User;
use App\Services\TransferService;

/*
 * ACCOUNT-002: edit or remove an account.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->checking()->create(['budget_id' => $this->budget->id, 'name' => 'Checking']);
});

function accountEditPayload(array $overrides = []): array
{
    return array_replace(['name' => 'Checking', 'type' => 'checking', 'include_in_budget' => true], $overrides);
}

test('a custom logo must be an image data URL or https URL within the size limit', function (string $logo) {
    $this->actingAs($this->user)
        ->patch(route('budgets.accounts.update', [$this->budget, $this->account]), accountEditPayload(['custom_logo' => $logo]))
        ->assertSessionHasErrors('custom_logo');

    expect($this->account->fresh()->custom_logo)->toBeNull();
})->with([
    'plain text' => 'not a logo',
    'non-image data URL' => 'data:text/html;base64,PHNjcmlwdD4=',
    'http URL' => 'http://example.com/logo.png',
    'javascript URL' => 'javascript:alert(1)',
    'oversized image' => fn () => 'data:image/png;base64,'.str_repeat('A', 70000),
]);

test('a valid custom logo is saved', function (string $logo) {
    $this->actingAs($this->user)
        ->patch(route('budgets.accounts.update', [$this->budget, $this->account]), accountEditPayload(['custom_logo' => $logo]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('budgets.show', $this->budget));

    expect($this->account->fresh()->custom_logo)->toBe($logo);
})->with([
    'png data URL' => 'data:image/png;base64,iVBORw0KGgo=',
    'svg data URL' => 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=',
    'https URL' => 'https://example.com/logo.png',
]);

test('a logo resized in the browser to 128 pixels fits the server limit and is saved', function () {
    // What the edit page uploads after resizing: a 128x128 PNG data URL.
    $image = imagecreatetruecolor(128, 128);
    for ($x = 0; $x < 128; $x += 8) {
        imagefilledrectangle($image, $x, 0, $x + 7, 127, imagecolorallocate($image, $x * 2, 255 - $x, ($x * 7) % 256));
    }
    ob_start();
    imagepng($image, null, 9);
    $logo = 'data:image/png;base64,'.base64_encode(ob_get_clean());

    expect(strlen($logo))->toBeLessThanOrEqual(65535);

    $this->actingAs($this->user)
        ->patch(route('budgets.accounts.update', [$this->budget, $this->account]), accountEditPayload(['custom_logo' => $logo]))
        ->assertSessionHasNoErrors();

    expect($this->account->fresh()->custom_logo)->toBe($logo);
})->skip(fn () => ! function_exists('imagecreatetruecolor'), 'GD is not installed');

test('the server logo limit is the 65535 character column size', function () {
    $prefix = 'data:image/png;base64,';
    $atLimit = $prefix.str_repeat('A', 65535 - strlen($prefix));

    $this->actingAs($this->user)
        ->patch(route('budgets.accounts.update', [$this->budget, $this->account]), accountEditPayload(['custom_logo' => $atLimit]))
        ->assertSessionHasNoErrors();
    expect($this->account->fresh()->custom_logo)->toBe($atLimit);

    $this->actingAs($this->user)
        ->patch(route('budgets.accounts.update', [$this->budget, $this->account]), accountEditPayload(['custom_logo' => $atLimit.'A']))
        ->assertSessionHasErrors('custom_logo');
    expect($this->account->fresh()->custom_logo)->toBe($atLimit);
});

test('deleting an account that has transfers explains the transfers must be deleted first', function () {
    $savings = Account::factory()->create(['budget_id' => $this->budget->id, 'type' => 'savings']);
    app(TransferService::class)->create([
        'budget_id' => $this->budget->id,
        'from_account_id' => $this->account->id,
        'to_account_id' => $savings->id,
        'amount_in_cents' => 5000,
        'date' => now()->toDateString(),
        'description' => 'Move to savings',
    ]);

    $this->actingAs($this->user)
        ->from(route('budgets.accounts.edit', [$this->budget, $savings]))
        ->delete(route('budgets.accounts.destroy', [$this->budget, $savings]))
        ->assertRedirect(route('budgets.accounts.edit', [$this->budget, $savings]))
        ->assertSessionHas('error', 'This account has transfers. Delete its transfers first, then delete the account.');

    expect($savings->fresh())->not->toBeNull()
        ->and(Transfer::count())->toBe(1);
});
