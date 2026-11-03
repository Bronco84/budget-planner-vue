<?php

namespace App\Http\Controllers;

use App\Models\TrustedDevice;
use App\Services\DeviceTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TrustedDeviceController extends Controller
{
    public function __construct(
        private DeviceTokenService $deviceTokenService
    ) {}

    /**
     * Revoke a trusted device and end its live session.
     */
    public function revoke(Request $request, TrustedDevice $device): RedirectResponse
    {
        $user = $request->user();

        // Verify the device belongs to the user
        if ($device->user_id !== $user->id) {
            abort(403);
        }

        $this->deviceTokenService->endDeviceSession($device, $request->session()->getId());
        $device->delete();

        return back()->with('status', 'Device revoked successfully.');
    }

    /**
     * Revoke all trusted devices except the current one, and sign out every
     * other session of the user.
     *
     * The current device is the one this browser's device cookie belongs to.
     * The fingerprint alone cannot say: it is only browser family and OS, which
     * other machines share.
     */
    public function revokeAll(Request $request): RedirectResponse
    {
        $user = $request->user();
        $current = $this->deviceTokenService->currentDevice($user, $request);

        $user->trustedDevices()
            ->when($current, fn ($query) => $query->whereKeyNot($current->id))
            ->delete();

        $this->deviceTokenService->endOtherSessions($user, $request->session()->getId());

        return back()->with('status', 'All other devices revoked successfully.');
    }
}
