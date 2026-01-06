<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\PasskeyAuthController;
use App\Http\Controllers\MagicLinkController;
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
        ->name('magic-link.store');
    
    Route::get('magic-link/authenticate', [MagicLinkController::class, 'authenticate'])
        ->name('magic-link.authenticate');

    // Registration
    Route::get('register', [PasskeyAuthController::class, 'showRegistrationForm'])
        ->name('register');

    Route::post('register', [PasskeyAuthController::class, 'registerStore']);
});

Route::middleware('auth')->group(function () {
    // Passkey Management
    Route::get('passkey/register', [PasskeyAuthController::class, 'registerCreate'])
        ->name('passkey.register');
    
    // Trusted Devices Management
    Route::get('settings/trusted-devices', [TrustedDeviceController::class, 'index'])
        ->name('trusted-devices.index');
    
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
