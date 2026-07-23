<?php

use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use Illuminate\Http\Request;

test('security headers are present in production', function () {
    // Security headers middleware checks app()->environment() which is set at boot
    // This test verifies the middleware exists and logic is correct
    $middleware = new SecurityHeaders;
    $request = Request::create('/test');

    // Mock production environment
    app()->instance('env', 'production');

    $response = $middleware->handle($request, function ($req) {
        return response('test');
    });

    // Verify headers would be set in production
    expect($response->headers->has('X-Content-Type-Options'))->toBeTrue();
    expect($response->headers->has('X-Frame-Options'))->toBeTrue();
});

test('security headers are not added in local environment', function () {
    config(['app.env' => 'local']);

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->get(route('dashboard'));

    // Headers should not be present in local
    expect($response->headers->has('Strict-Transport-Security'))->toBeFalse();
});

test('core hardening headers are applied outside production', function () {
    $middleware = new SecurityHeaders;
    $request = Request::create('/test');

    // Simulate a non-local, non-production env (e.g. staging).
    app()->instance('env', 'staging');

    $response = $middleware->handle($request, fn ($req) => response('test'));

    expect($response->headers->has('X-Content-Type-Options'))->toBeTrue();
    expect($response->headers->has('X-Frame-Options'))->toBeTrue();
    expect($response->headers->has('Referrer-Policy'))->toBeTrue();
    // CSP is shipped in report-only mode (not yet enforcing) outside local.
    expect($response->headers->has('Content-Security-Policy-Report-Only'))->toBeTrue();
    expect($response->headers->get('Content-Security-Policy-Report-Only'))->toContain('cdn.plaid.com');
    // HSTS remains production-only.
    expect($response->headers->has('Strict-Transport-Security'))->toBeFalse();
});

test('HSTS header includes subdomains and preload', function () {
    // Test the middleware logic directly
    $middleware = new SecurityHeaders;
    $request = Request::create('/test');

    app()->instance('env', 'production');

    $response = $middleware->handle($request, function ($req) {
        return response('test');
    });

    $hstsHeader = $response->headers->get('Strict-Transport-Security');

    expect($hstsHeader)->toBeString();
    expect($hstsHeader)->toContain('includeSubDomains');
    expect($hstsHeader)->toContain('preload');
    expect($hstsHeader)->toContain('max-age=31536000');
});
