<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Laragear\WebAuthn\Models\WebAuthnCredential;

class PasskeyManagementController extends Controller
{
    /**
     * Display the user's passkeys.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        
        // Get all WebAuthn credentials for the user
        $passkeys = $user->webAuthnCredentials()
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(function ($credential) {
                return [
                    'id' => $credential->id,
                    'name' => $credential->alias ?? 'Unnamed Passkey',
                    'created_at' => $credential->created_at,
                    'updated_at' => $credential->updated_at,
                    'last_used' => $credential->updated_at, // WebAuthn updates this on each use
                    'can_delete' => true, // Always allow deletion
                ];
            });

        return Inertia::render('Settings/Passkeys', [
            'passkeys' => $passkeys,
        ]);
    }

    /**
     * Update a passkey's name/alias.
     */
    public function update(Request $request, WebAuthnCredential $credential): RedirectResponse
    {
        // Verify the credential belongs to the authenticated user
        if ($credential->authenticatable_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        // Use forceFill since alias is not in the model's fillable array
        $credential->forceFill([
            'alias' => $validated['name'],
        ])->save();

        return back()->with('status', 'Passkey name updated successfully.');
    }

    /**
     * Delete a passkey.
     */
    public function destroy(Request $request, WebAuthnCredential $credential): RedirectResponse
    {
        $user = $request->user();

        // Verify the credential belongs to the authenticated user
        if ($credential->authenticatable_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        // Check if this is the user's last passkey
        $passkeyCount = $user->webAuthnCredentials()->count();
        
        if ($passkeyCount <= 1) {
            return back()->withErrors([
                'passkey' => 'You cannot delete your last passkey. Please add another passkey or authentication method first.',
            ]);
        }

        $credential->delete();

        return back()->with('status', 'Passkey deleted successfully. Remember to remove it from your device\'s password manager if needed.');
    }
}

