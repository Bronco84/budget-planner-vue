<?php

use App\Models\User;

test('non-admin users cannot access nova', function () {
    $user = User::factory()->create(['is_admin' => false]);

    expect($user->can('viewNova'))->toBeFalse();
});

test('admin users can access nova', function () {
    $user = User::factory()->create(['is_admin' => true]);

    expect($user->can('viewNova'))->toBeTrue();
});

test('users without is_admin flag cannot access nova', function () {
    $user = User::factory()->create();
    // Explicitly set is_admin to null/false
    $user->is_admin = false;
    $user->save();

    expect($user->can('viewNova'))->toBeFalse();
});

test('is_admin must be explicitly true to access nova', function () {
    $user = User::factory()->create(['is_admin' => true]);
    expect($user->can('viewNova'))->toBeTrue();

    // Set to false explicitly. Note: is_admin is intentionally NOT mass-assignable
    // (guarded against privilege escalation), so it must be set on the attribute
    // directly rather than via update([...]).
    $user->is_admin = false;
    $user->save();
    $user->refresh();
    expect($user->can('viewNova'))->toBeFalse();
});

test('is_admin cannot be mass-assigned via fill', function () {
    $user = User::factory()->create(['is_admin' => false]);

    // Attempting to mass-assign the privilege flag must be silently ignored.
    $user->fill(['is_admin' => true]);
    $user->save();
    $user->refresh();

    expect($user->is_admin)->toBeFalse();
    expect($user->can('viewNova'))->toBeFalse();
});

test('nova access depends on is_admin only, not on an email allowlist', function () {
    $owner = User::factory()->create(['email' => 'bamccoley@gmail.com']);
    $owner->is_admin = false;
    $owner->save();

    expect($owner->fresh()->can('viewNova'))->toBeFalse();
});

test('the owner admin migration grants is_admin to the previously allowlisted email', function () {
    $owner = User::factory()->create(['email' => 'BAMcColey@gmail.com']);
    $other = User::factory()->create();
    $owner->is_admin = false;
    $owner->save();

    $migration = require database_path('migrations/2026_10_31_250000_grant_admin_to_owner_account.php');
    $migration->up();

    expect($owner->fresh()->is_admin)->toBeTrue()
        ->and($other->fresh()->is_admin)->toBeFalse()
        ->and($owner->fresh()->can('viewNova'))->toBeTrue();

    // down() is a deliberate no-op so a rollback never locks the owner out.
    $migration->down();
    expect($owner->fresh()->is_admin)->toBeTrue();
});

test('the owner admin migration is a no-op when the owner account does not exist', function () {
    $user = User::factory()->create();

    $migration = require database_path('migrations/2026_10_31_250000_grant_admin_to_owner_account.php');
    $migration->up();

    expect($user->fresh()->is_admin)->toBeFalse();
});
