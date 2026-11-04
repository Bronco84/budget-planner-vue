<?php

/**
 * Reload config/session.php into the config repository as production would
 * resolve it (APP_ENV=production, no SESSION_* overrides), so the tests read
 * config('session.*') instead of re-implementing the file's env() calls.
 */
function loadProductionSessionConfig(): void
{
    $keys = ['APP_ENV', 'SESSION_SAME_SITE', 'SESSION_SECURE_COOKIE'];
    $saved = [];

    foreach ($keys as $key) {
        $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }

    $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'production';
    putenv('APP_ENV=production');

    try {
        config()->set('session', require base_path('config/session.php'));
    } finally {
        foreach ($saved as $key => [$env, $server, $put]) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            if ($env !== null) {
                $_ENV[$key] = $env;
            }
            if ($server !== null) {
                $_SERVER[$key] = $server;
            }
            if ($put !== false) {
                putenv("{$key}={$put}");
            }
        }
    }
}

test('session secure cookie defaults to true in production environment', function () {
    loadProductionSessionConfig();

    expect(config('session.secure'))->toBeTrue();
});

test('session same_site defaults to lax in production environment', function () {
    loadProductionSessionConfig();

    // Strict would withhold the session on cross-site returns such as the
    // Google OAuth callback and magic links opened from webmail.
    expect(config('session.same_site'))->toBe('lax');
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
