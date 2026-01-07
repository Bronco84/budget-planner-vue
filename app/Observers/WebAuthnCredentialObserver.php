<?php

namespace App\Observers;

use Laragear\WebAuthn\Models\WebAuthnCredential;
use Illuminate\Support\Str;

class WebAuthnCredentialObserver
{
    /**
     * Handle the WebAuthnCredential "creating" event.
     * Automatically set a friendly alias based on device information.
     */
    public function creating(WebAuthnCredential $credential): void
    {
        // Only set alias if it's not already set
        if (empty($credential->alias)) {
            $credential->alias = $this->generateDeviceName($credential);
        }
    }

    /**
     * Generate a friendly device name based on available information.
     */
    protected function generateDeviceName(WebAuthnCredential $credential): string
    {
        $request = request();
        
        // Get user agent information
        $userAgent = $request->userAgent();
        $platform = $this->detectPlatform($userAgent);
        $browser = $this->detectBrowser($userAgent);
        
        // Get device type from authenticator data if available
        $deviceType = $this->detectDeviceType($userAgent);
        
        // Build a friendly name
        $parts = array_filter([$deviceType, $platform, $browser]);
        $baseName = implode(' - ', $parts) ?: 'Passkey';
        
        // Check if user already has a passkey with this name
        $existingCount = WebAuthnCredential::where('authenticatable_id', $credential->authenticatable_id)
            ->where('authenticatable_type', $credential->authenticatable_type)
            ->where('alias', 'LIKE', $baseName . '%')
            ->count();
        
        if ($existingCount > 0) {
            $baseName .= ' (' . ($existingCount + 1) . ')';
        }
        
        return $baseName;
    }

    /**
     * Detect the platform from user agent.
     */
    protected function detectPlatform(string $userAgent): ?string
    {
        if (Str::contains($userAgent, ['iPhone', 'iPad', 'iPod'])) {
            return 'iOS';
        }
        
        if (Str::contains($userAgent, 'Mac OS X')) {
            return 'macOS';
        }
        
        if (Str::contains($userAgent, 'Windows')) {
            return 'Windows';
        }
        
        if (Str::contains($userAgent, 'Android')) {
            return 'Android';
        }
        
        if (Str::contains($userAgent, 'Linux')) {
            return 'Linux';
        }
        
        return null;
    }

    /**
     * Detect the browser from user agent.
     */
    protected function detectBrowser(string $userAgent): ?string
    {
        if (Str::contains($userAgent, 'Edg')) {
            return 'Edge';
        }
        
        if (Str::contains($userAgent, 'Chrome') && !Str::contains($userAgent, 'Edg')) {
            return 'Chrome';
        }
        
        if (Str::contains($userAgent, 'Safari') && !Str::contains($userAgent, ['Chrome', 'Edg'])) {
            return 'Safari';
        }
        
        if (Str::contains($userAgent, 'Firefox')) {
            return 'Firefox';
        }
        
        return null;
    }

    /**
     * Detect the device type from user agent.
     */
    protected function detectDeviceType(string $userAgent): ?string
    {
        if (Str::contains($userAgent, ['iPhone', 'iPod'])) {
            return 'iPhone';
        }
        
        if (Str::contains($userAgent, 'iPad')) {
            return 'iPad';
        }
        
        if (Str::contains($userAgent, 'Android') && Str::contains($userAgent, 'Mobile')) {
            return 'Android Phone';
        }
        
        if (Str::contains($userAgent, 'Android')) {
            return 'Android Tablet';
        }
        
        // Desktop devices
        if (Str::contains($userAgent, ['Windows', 'Mac OS X', 'Linux']) && 
            !Str::contains($userAgent, ['iPhone', 'iPad', 'Android'])) {
            return 'Computer';
        }
        
        return null;
    }
}

