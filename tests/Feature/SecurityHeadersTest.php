<?php

use App\Models\User;

test('security headers are present in production', function () {
    // Security headers middleware checks app()->environment() which is set at boot
    // This test verifies the middleware exists and logic is correct
    $middleware = new \App\Http\Middleware\SecurityHeaders();
    $request = \Illuminate\Http\Request::create('/test');
    
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

test('HSTS header includes subdomains and preload', function () {
    // Test the middleware logic directly
    $middleware = new \App\Http\Middleware\SecurityHeaders();
    $request = \Illuminate\Http\Request::create('/test');
    
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

