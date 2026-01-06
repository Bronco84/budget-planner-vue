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
    
    // Update to false
    $user->update(['is_admin' => false]);
    $user->refresh();
    expect($user->can('viewNova'))->toBeFalse();
});

