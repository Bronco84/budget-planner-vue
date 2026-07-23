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
