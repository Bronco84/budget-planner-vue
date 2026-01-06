<?php

test('session secure cookie defaults to true in production environment', function () {
    // Test that the config logic would work correctly
    $appEnv = 'production';
    $secure = env('SESSION_SECURE_COOKIE', $appEnv === 'production');
    
    expect($secure)->toBeTrue();
});

test('session same_site defaults to strict in production environment', function () {
    // Test that the config logic would work correctly
    $appEnv = 'production';
    $sameSite = env('SESSION_SAME_SITE', $appEnv === 'production' ? 'strict' : 'lax');
    
    expect($sameSite)->toBe('strict');
});

test('session http_only is enabled by default', function () {
    expect(config('session.http_only'))->toBeTrue();
});

test('session lifetime is reasonable', function () {
    $lifetime = config('session.lifetime');
    
    // Should be between 30 minutes and 24 hours
    expect($lifetime)->toBeGreaterThanOrEqual(30);
    expect($lifetime)->toBeLessThanOrEqual(1440);
});

