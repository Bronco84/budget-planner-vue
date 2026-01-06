<?php

namespace App\Http\Middleware;

use App\Services\DeviceTokenService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RememberDevice
{
    public function __construct(
        private DeviceTokenService $deviceTokenService
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip if user is already authenticated
        if (Auth::check()) {
            return $next($request);
        }

        // Don't auto-login on logout route
        if ($request->is('logout')) {
            return $next($request);
        }

        // Check for device token cookie
        $token = $request->cookie($this->deviceTokenService->getCookieName());
        
        if ($token) {
            $device = $this->deviceTokenService->verifyDeviceToken($token, $request);
            
            if ($device && $device->isValid()) {
                // Auto-login the user
                Auth::loginUsingId($device->user_id);
                
                // Update last used timestamp
                $device->markAsUsed();
                
                return $next($request);
            }
        }

        return $next($request);
    }
}
