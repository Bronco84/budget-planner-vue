<?php

namespace App\Observers;

use App\Services\LegacyWebAuthnCredentials;
use Illuminate\Support\Str;
use Laravel\Passkeys\Passkey;

class PasskeyObserver
{
    /**
     * Handle the Passkey "creating" event.
     * Automatically set a friendly name based on device information.
     */
    public function creating(Passkey $passkey): void
    {
        // Only set a name if the user did not type one
        if (blank($passkey->name)) {
            $passkey->name = $this->generateDeviceName($passkey);
        }
    }

    /**
     * Drop the laragear copy of a removed passkey, so rolling back the move to
     * laravel/passkeys cannot bring it back.
     */
    public function deleted(Passkey $passkey): void
    {
        LegacyWebAuthnCredentials::deleteByCredentialId($passkey->credential_id);
    }

    /**
     * Generate a friendly device name based on available information.
     */
    protected function generateDeviceName(Passkey $passkey): string
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
        $existingCount = Passkey::where('user_id', $passkey->user_id)
            ->where('name', 'LIKE', $baseName.'%')
            ->count();

        if ($existingCount > 0) {
            $baseName .= ' ('.($existingCount + 1).')';
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

        if (Str::contains($userAgent, 'Chrome') && ! Str::contains($userAgent, 'Edg')) {
            return 'Chrome';
        }

        if (Str::contains($userAgent, 'Safari') && ! Str::contains($userAgent, ['Chrome', 'Edg'])) {
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
            ! Str::contains($userAgent, ['iPhone', 'iPad', 'Android'])) {
            return 'Computer';
        }

        return null;
    }
}
