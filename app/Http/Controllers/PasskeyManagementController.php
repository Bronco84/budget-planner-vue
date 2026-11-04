<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Passkeys\Passkey;

class PasskeyManagementController extends Controller
{
    /**
     * Update a passkey's name/alias.
     */
    public function update(Request $request, Passkey $credential): RedirectResponse
    {
        // Verify the credential belongs to the authenticated user
        if ((string) $credential->user_id !== (string) $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $credential->update(['name' => $validated['name']]);

        return back()->with('status', 'Passkey name updated successfully.');
    }

    /**
     * Delete a passkey.
     */
    public function destroy(Request $request, Passkey $credential): RedirectResponse
    {
        $user = $request->user();

        // Verify the credential belongs to the authenticated user
        if ((string) $credential->user_id !== (string) $user->id) {
            abort(403, 'Unauthorized');
        }

        // Check if this is the user's last passkey
        $passkeyCount = $user->passkeys()->count();

        if ($passkeyCount <= 1) {
            return back()->withErrors([
                'passkey' => 'You cannot delete your last passkey. Please add another passkey or authentication method first.',
            ]);
        }

        // Deleted as a model so PasskeyObserver also drops the legacy laragear copy.
        $credential->delete();

        return back()->with('status', 'Passkey deleted successfully. Remember to remove it from your device\'s password manager if needed.');
    }
}
