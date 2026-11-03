<?php

namespace App\Services;

use App\Mail\NewDeviceLogin;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

class DeviceTokenService
{
    public function __construct(
        private DeviceFingerprintService $fingerprintService
    ) {}

    /**
     * Create or update a trusted device for the user.
     *
     * The browser is recognised by the device cookie it already holds, not by
     * its fingerprint: the fingerprint is only browser family and OS, which
     * many machines share, so matching on it alone would let a second laptop
     * silently take over the first one's row without a new-device alert.
     */
    public function createTrustedDevice(User $user, Request $request, string $authMethod = 'passkey'): TrustedDevice
    {
        $fingerprint = $this->fingerprintService->generate($request);
        $deviceName = $this->fingerprintService->generateDeviceName($request);

        $token = $request->cookie($this->getCookieName());
        $device = is_string($token) && $token !== '' ? $this->findByToken($token) : null;

        if ($device && $device->user_id === $user->id) {
            // Same browser re-authenticating: refresh it. This also moves a
            // legacy or stale fingerprint to the current format, so a browser
            // trusted before the format change does not raise a new-device alert.
            $device->update([
                'device_name' => $deviceName,
                'device_fingerprint' => $fingerprint,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'last_used_at' => now(),
                'expires_at' => now()->addDays((int) config('auth.device_remember_days', 90)),
                'auth_method' => $authMethod, // Update auth method
                'session_id' => $this->sessionId($request),
            ]);

            return $device;
        }

        // Create new device
        $token = Str::random(64);

        $device = $user->trustedDevices()->create([
            'device_name' => $deviceName,
            'device_fingerprint' => $fingerprint,
            'device_token' => Crypt::encryptString($token),
            'token_hash' => self::hashToken($token),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'last_used_at' => now(),
            'expires_at' => now()->addDays((int) config('auth.device_remember_days', 90)),
            'auth_method' => $authMethod,
            'session_id' => $this->sessionId($request),
        ]);

        $this->sendNewDeviceAlert($user, $device);

        return $device;
    }

    /**
     * The id of the request's session. Callers run after the login has
     * regenerated the session, so this is the id the browser now holds.
     */
    private function sessionId(Request $request): ?string
    {
        return $request->hasSession() ? $request->session()->getId() : null;
    }

    /**
     * End the live session a revoked device last signed in with, unless it
     * is the session making the request.
     */
    public function endDeviceSession(TrustedDevice $device, ?string $currentSessionId): void
    {
        if ($device->session_id === null || $device->session_id === $currentSessionId) {
            return;
        }

        $this->sessions()
            ?->where('user_id', $device->user_id)
            ->where('id', $device->session_id)
            ->delete();
    }

    /**
     * End every live session of the user except the one making the request
     * (this also covers magic-link sessions, which have no trusted device).
     */
    public function endOtherSessions(User $user, ?string $currentSessionId): void
    {
        $this->sessions()
            ?->where('user_id', $user->id)
            ->when($currentSessionId !== null, fn ($query) => $query->where('id', '!=', $currentSessionId))
            ->delete();
    }

    /**
     * Query on the stored sessions, or null when the session driver does not
     * keep them in the database.
     */
    private function sessions(): ?Builder
    {
        if (config('session.driver') !== 'database') {
            return null;
        }

        return DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'));
    }

    /**
     * Email the user about a sign-in from a new device. Queued only when a
     * worker is known to be running (auth.magic_link_queue, the same switch
     * as magic links); a mail failure is reported but never fails the
     * sign-in or registration that already succeeded.
     */
    private function sendNewDeviceAlert(User $user, TrustedDevice $device): void
    {
        try {
            $mail = Mail::to($user->email);
            $message = new NewDeviceLogin($user, $device);

            config('auth.magic_link_queue') ? $mail->queue($message) : $mail->send($message);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Verify a device token and return the device if valid.
     *
     * The token must match and the request must come from the same browser
     * family and OS. A device still carrying a legacy fingerprint is moved to
     * the current format on its first successful use.
     */
    public function verifyDeviceToken(string $token, Request $request): ?TrustedDevice
    {
        $device = $this->findByToken($token);

        if (! $device || ! $this->fingerprintService->verify($request, $device->device_fingerprint)) {
            return null;
        }

        $current = $this->fingerprintService->generate($request);

        if (! hash_equals($device->device_fingerprint, $current)) {
            $device->forceFill(['device_fingerprint' => $current])->saveQuietly();
        }

        return $device;
    }

    /**
     * The trusted device this browser's cookie belongs to, if it is the user's.
     */
    public function currentDevice(User $user, Request $request): ?TrustedDevice
    {
        $token = $request->cookie($this->getCookieName());

        if (! is_string($token) || $token === '') {
            return null;
        }

        $device = $this->verifyDeviceToken($token, $request);

        return $device && $device->user_id === $user->id ? $device : null;
    }

    /**
     * Find the valid device a raw token belongs to, without checking the browser.
     */
    private function findByToken(string $token): ?TrustedDevice
    {
        try {
            $hash = self::hashToken($token);

            // Indexed lookup by the token hash.
            $candidates = TrustedDevice::valid()->where('token_hash', $hash)->get();

            foreach ($candidates as $device) {
                if ($this->tokenMatches($device, $token)) {
                    return $device;
                }
            }

            // Legacy fallback: devices created before token_hash existed (or whose
            // token could not be backfilled) are scanned and, on a match, given a
            // hash so later requests use the indexed lookup.
            $legacy = TrustedDevice::valid()->whereNull('token_hash')->get();

            foreach ($legacy as $device) {
                if ($this->tokenMatches($device, $token)) {
                    $device->forceFill(['token_hash' => $hash])->saveQuietly();

                    return $device;
                }
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Deterministic lookup hash of a raw device token.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Constant-time comparison of the raw token against the device's encrypted token.
     */
    private function tokenMatches(TrustedDevice $device, string $token): bool
    {
        try {
            return hash_equals(Crypt::decryptString($device->device_token), $token);
        } catch (\Exception $e) {
            return false;
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
    public function createCookie(TrustedDevice $device): Cookie
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
    public function forgetCookie(): Cookie
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
