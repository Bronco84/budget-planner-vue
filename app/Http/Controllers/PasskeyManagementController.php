<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laragear\WebAuthn\Models\WebAuthnCredential;

class PasskeyManagementController extends Controller
{
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
