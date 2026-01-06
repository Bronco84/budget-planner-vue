<?php

namespace App\Services;

use App\Models\User;
use App\Models\TrustedDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Carbon\Carbon;

class DeviceTokenService
{
    public function __construct(
        private DeviceFingerprintService $fingerprintService
    ) {}

    /**
     * Create or update a trusted device for the user.
     */
    public function createTrustedDevice(User $user, Request $request): TrustedDevice
    {
        $fingerprint = $this->fingerprintService->generate($request);
        $deviceName = $this->fingerprintService->generateDeviceName($request);
        
        // Check if a device with this fingerprint already exists for this user
        $device = $user->trustedDevices()
            ->where('device_fingerprint', $fingerprint)
            ->first();
        
        if ($device) {
            // Update existing device
            $device->update([
                'device_name' => $deviceName,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'last_used_at' => now(),
                'expires_at' => now()->addDays((int) config('auth.device_remember_days', 90)),
            ]);
            
            return $device;
        }
        
        // Create new device
        $token = Str::random(64);
        
        $device = $user->trustedDevices()->create([
            'device_name' => $deviceName,
            'device_fingerprint' => $fingerprint,
            'device_token' => Crypt::encryptString($token),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'last_used_at' => now(),
            'expires_at' => now()->addDays((int) config('auth.device_remember_days', 90)),
        ]);
        
        return $device;
    }

    /**
     * Verify a device token and return the device if valid.
     */
    public function verifyDeviceToken(string $token, Request $request): ?TrustedDevice
    {
        try {
            // Find all devices and check each one
            $devices = TrustedDevice::valid()->get();
            
            foreach ($devices as $device) {
                try {
                    $decryptedToken = Crypt::decryptString($device->device_token);
                    
                    if ($decryptedToken === $token) {
                        // Verify fingerprint matches
                        if ($this->fingerprintService->verify($request, $device->device_fingerprint)) {
                            return $device;
                        }
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
            
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the device token cookie name.
     */
    public function getCookieName(): string
    {
        return 'device_token';
    }

    /**
     * Create a cookie with the device token.
     */
    public function createCookie(TrustedDevice $device): \Symfony\Component\HttpFoundation\Cookie
    {
        $token = Crypt::decryptString($device->device_token);
        
        return cookie(
            $this->getCookieName(),
            $token,
            (int) config('auth.device_remember_days', 90) * 24 * 60, // minutes
            '/',
            null,
            true, // secure
            true, // httpOnly
            false,
            'lax'
        );
    }

    /**
     * Remove the device token cookie.
     */
    public function forgetCookie(): \Symfony\Component\HttpFoundation\Cookie
    {
        return cookie()->forget($this->getCookieName());
    }

    /**
     * Clean up expired devices.
     */
    public function cleanupExpiredDevices(): int
    {
        return TrustedDevice::expired()->delete();
    }
}

