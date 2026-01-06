<?php

namespace App\Http\Controllers;

use App\Models\TrustedDevice;
use App\Services\DeviceFingerprintService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Diglactic\Breadcrumbs\Breadcrumbs;

class TrustedDeviceController extends Controller
{
    public function __construct(
        private DeviceFingerprintService $fingerprintService
    ) {}

    /**
     * Display the user's trusted devices.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $currentFingerprint = $this->fingerprintService->generate($request);

        $devices = $user->trustedDevices()
            ->valid()
            ->orderBy('last_used_at', 'desc')
            ->get()
            ->map(function ($device) use ($currentFingerprint) {
                return [
                    'id' => $device->id,
                    'device_name' => $device->device_name,
                    'ip_address' => $device->ip_address,
                    'last_used_at' => $device->last_used_at,
                    'last_used_human' => $device->last_used_human,
                    'expires_at' => $device->expires_at,
                    'created_at' => $device->created_at,
                    'is_current' => $device->isCurrentDevice($currentFingerprint),
                    'auth_method' => $device->auth_method,
                ];
            });

        return Inertia::render('Settings/TrustedDevices', [
            'trustedDevices' => $devices,
        ])->with('breadcrumbs', function () {
            return Breadcrumbs::generate('trusted-devices.index');
        });
    }

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
