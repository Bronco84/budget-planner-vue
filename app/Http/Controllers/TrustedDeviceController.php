<?php

namespace App\Http\Controllers;

use App\Models\TrustedDevice;
use App\Services\DeviceFingerprintService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TrustedDeviceController extends Controller
{
    public function __construct(
        private DeviceFingerprintService $fingerprintService
    ) {}

    /**
     * Revoke a trusted device.
     */
    public function revoke(Request $request, TrustedDevice $device): RedirectResponse
    {
        $user = $request->user();

        // Verify the device belongs to the user
        if ($device->user_id !== $user->id) {
            abort(403);
        }

        $device->delete();

        return back()->with('status', 'Device revoked successfully.');
    }

    /**
     * Revoke all trusted devices except the current one.
     */
    public function revokeAll(Request $request): RedirectResponse
    {
        $user = $request->user();
        $currentFingerprint = $this->fingerprintService->generate($request);

        // Delete all devices except the current one
        $user->trustedDevices()
            ->where('device_fingerprint', '!=', $currentFingerprint)
            ->delete();

        return back()->with('status', 'All other devices revoked successfully.');
    }
}
