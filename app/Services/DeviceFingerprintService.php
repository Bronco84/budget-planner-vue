<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class DeviceFingerprintService
{
    /**
     * Generate a device fingerprint from request data.
     */
    public function generate(Request $request): string
    {
        $components = [
            $request->userAgent() ?? '',
            $request->header('Accept-Language') ?? '',
            $request->header('Accept-Encoding') ?? '',
            $request->header('Accept') ?? '',
        ];

        $fingerprint = implode('|', $components);
        
        return hash('sha256', $fingerprint);
    }

    /**
     * Generate a human-readable device name from request data.
     */
    public function generateDeviceName(Request $request): string
    {
        $userAgent = $request->userAgent() ?? 'Unknown Device';
        
        // Extract browser name
        $browser = $this->extractBrowser($userAgent);
        
        // Extract OS
        $os = $this->extractOS($userAgent);
        
        return "{$browser} on {$os}";
    }

    /**
     * Extract browser name from user agent.
     */
    private function extractBrowser(string $userAgent): string
    {
        if (str_contains($userAgent, 'Edg')) {
            return 'Edge';
        }
        if (str_contains($userAgent, 'Chrome')) {
            return 'Chrome';
        }
        if (str_contains($userAgent, 'Safari') && !str_contains($userAgent, 'Chrome')) {
            return 'Safari';
        }
        if (str_contains($userAgent, 'Firefox')) {
            return 'Firefox';
        }
        if (str_contains($userAgent, 'Opera') || str_contains($userAgent, 'OPR')) {
            return 'Opera';
        }
        
        return 'Unknown Browser';
    }

    /**
     * Extract OS from user agent.
     */
    private function extractOS(string $userAgent): string
    {
        if (str_contains($userAgent, 'Windows NT 10.0')) {
            return 'Windows 10/11';
        }
        if (str_contains($userAgent, 'Windows NT')) {
            return 'Windows';
        }
        if (str_contains($userAgent, 'Mac OS X')) {
            return 'macOS';
        }
        if (str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad')) {
            return 'iOS';
        }
        if (str_contains($userAgent, 'Android')) {
            return 'Android';
        }
        if (str_contains($userAgent, 'Linux')) {
            return 'Linux';
        }
        
        return 'Unknown OS';
    }

    /**
     * Verify if a fingerprint matches the current request.
     */
    public function verify(Request $request, string $storedFingerprint): bool
    {
        $currentFingerprint = $this->generate($request);
        
        return $currentFingerprint === $storedFingerprint;
    }

    /**
     * Get approximate location from IP address (basic implementation).
     */
    public function getLocation(string $ipAddress): ?string
    {
        // For local development
        if ($ipAddress === '127.0.0.1' || $ipAddress === '::1') {
            return 'Local';
        }
        
        // In production, you could integrate with a GeoIP service
        // For now, just return null
        return null;
    }
}

