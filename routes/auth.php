<?php

use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\MagicLinkController;
use App\Http\Controllers\PasskeyAuthController;
use App\Http\Controllers\PasskeyManagementController;
use App\Http\Controllers\TrustedDeviceController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    // Passkey Authentication (Primary)
    Route::get('login', [PasskeyAuthController::class, 'create'])
        ->name('login');

    // Magic Link Fallback
    Route::get('magic-link', [MagicLinkController::class, 'create'])
        ->name('magic-link.request');

    Route::post('magic-link', [MagicLinkController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('magic-link.store');

    Route::get('magic-link/authenticate', [MagicLinkController::class, 'authenticate'])
        ->middleware('throttle:10,1')
        ->name('magic-link.authenticate');

    // Registration
    Route::get('register', [PasskeyAuthController::class, 'showRegistrationForm'])
        ->name('register');

    Route::post('register', [PasskeyAuthController::class, 'registerStore'])
        ->middleware('throttle:6,1');
});

Route::middleware('auth')->group(function () {
    // Passkey Management
    Route::get('passkey/register', [PasskeyAuthController::class, 'registerCreate'])
        ->name('passkey.register');

    Route::patch('settings/passkeys/{credential}', [PasskeyManagementController::class, 'update'])
        ->name('passkeys.update');

    Route::delete('settings/passkeys/{credential}', [PasskeyManagementController::class, 'destroy'])
        ->name('passkeys.destroy');

    // Trusted Devices Management
    Route::delete('settings/trusted-devices/{device}', [TrustedDeviceController::class, 'revoke'])
        ->name('trusted-devices.revoke');

    Route::post('settings/trusted-devices/revoke-all', [TrustedDeviceController::class, 'revokeAll'])
        ->name('trusted-devices.revoke-all');

    // Email Verification
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // Logout
    Route::post('logout', [PasskeyAuthController::class, 'destroy'])
        ->name('logout');
});
