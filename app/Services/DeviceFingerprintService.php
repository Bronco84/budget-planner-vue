<?php

namespace App\Services;

use Illuminate\Http\Request;

class DeviceFingerprintService
{
    /**
     * Generate a device fingerprint from request data.
     *
     * The fingerprint is a coarse second factor next to the device cookie
     * token: browser family and operating system only, with no versions, so a
     * browser update does not break trust. It deliberately leaves out the
     * Accept headers (the device is trusted during a JSON passkey-login request
     * but later page loads send an HTML Accept, and browsers change their
     * Accept-Encoding list between releases).
     */
    public function generate(Request $request): string
    {
        $userAgent = $request->userAgent() ?? '';

        return hash('sha256', implode('|', [
            'v2',
            $this->extractBrowser($userAgent),
            $this->extractOS($userAgent),
        ]));
    }

    /**
     * The fingerprint format used before browser versions were dropped: the
     * full User-Agent plus Accept-Language and Accept-Encoding. Devices trusted
     * before the change still carry it until their next use upgrades them.
     */
    public function generateLegacy(Request $request): string
    {
        return hash('sha256', implode('|', [
            $request->userAgent() ?? '',
            $request->header('Accept-Language') ?? '',
            $request->header('Accept-Encoding') ?? '',
        ]));
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
        if (str_contains($userAgent, 'Safari') && ! str_contains($userAgent, 'Chrome')) {
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
        // iOS user agents also say "like Mac OS X", so check them first.
        if (str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad')) {
            return 'iOS';
        }
        if (str_contains($userAgent, 'Mac OS X') || str_contains($userAgent, 'Macintosh')) {
            return 'macOS';
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
     * Verify if a fingerprint matches the current request, in either the
     * current or the legacy format.
     */
    public function verify(Request $request, string $storedFingerprint): bool
    {
        return hash_equals($storedFingerprint, $this->generate($request))
            || hash_equals($storedFingerprint, $this->generateLegacy($request));
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
