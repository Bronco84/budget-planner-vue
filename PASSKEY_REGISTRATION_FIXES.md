# Passkey Registration Fixes

## Issues Fixed

### 1. Registration Route Exposing Dashboard
**Problem:** After creating an account via `/register`, users were redirected to the passkey registration page which used `AuthenticatedLayout`, exposing the full dashboard/sidebar before they had completed setup.

**Solution:**
- Updated `PasskeyRegister.vue` to use dynamic layout component
- Uses `GuestLayout` for new users (clean, focused registration experience)
- Uses `AuthenticatedLayout` for existing users adding additional passkeys
- Added `isNewUser` prop to differentiate between first-time registration and adding passkeys

### 2. No Passkeys Listed After Registration
**Problem:** Users could complete the passkey registration process, but no passkeys were shown in the trusted devices list.

**Root Cause:** The `registerCreate` method wasn't checking if the user had existing passkeys, always showing `hasExistingPasskeys: false`.

**Solution:**
- Updated `PasskeyAuthController@registerCreate` to check for existing WebAuthn credentials
- Properly sets `hasExistingPasskeys` based on actual database query
- Passes `isNewUser` flag to differentiate new account setup from adding passkeys

### 3. Passkey Registration Not Enforced
**Problem:** The app is designed to be passwordless and require passkeys, but there was no enforcement preventing users from accessing the app without registering a passkey.

**Solution:**
- Created `EnsureUserHasPasskey` middleware to enforce passkey requirement
- Users without passkeys are redirected to passkey registration page
- Cannot access any part of the app until passkey is registered
- Clear warning message that passkey is required (not optional)
- Auto-redirects to `/budgets` after successful passkey registration
- Improved UI to emphasize that passkey is mandatory

## Code Changes

### PasskeyAuthController.php
```php
public function registerCreate(): Response
{
    $user = Auth::user();
    $hasExistingPasskeys = $user ? $user->webAuthnCredentials()->exists() : false;
    $isNewUser = $user && !$hasExistingPasskeys;
    
    return Inertia::render('Auth/PasskeyRegister', [
        'hasExistingPasskeys' => $hasExistingPasskeys,
        'isNewUser' => $isNewUser,
    ])->with('breadcrumbs', function () {
        return Breadcrumbs::generate('passkey.register');
    });
}
```

### PasskeyRegister.vue
**Key Changes:**
1. Dynamic layout component:
```vue
<component :is="isNewUser ? GuestLayout : AuthenticatedLayout">
```

2. Welcome message for new users:
```vue
<span v-if="isNewUser">Welcome! To complete your registration, please set up a passkey. </span>
```

3. Warning message for new users:
```vue
<div v-if="isNewUser" class="mb-6 rounded-md bg-yellow-50 border border-yellow-200 p-4">
  <p class="text-sm font-medium text-yellow-800">
    Passkey Required: This app uses passkeys for secure, passwordless authentication. 
    You cannot proceed without registering a passkey.
  </p>
</div>
```

4. Auto-redirect after successful registration:
```javascript
if (registerResponse.ok) {
  success.value = 'Passkey registered successfully!';
  
  if (props.isNewUser) {
    setTimeout(() => {
      window.location.href = '/budgets';
    }, 1500);
  }
}
```

### EnsureUserHasPasskey Middleware
```php
class EnsureUserHasPasskey
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        // If user is authenticated but has no passkeys, redirect to registration
        if ($user && !$user->webAuthnCredentials()->exists()) {
            // Allow access to the passkey registration route itself
            if (!$request->is('passkey/register') && !$request->is('webauthn/*') && !$request->is('logout')) {
                return redirect()->route('passkey.register')
                    ->with('error', 'You must register a passkey to continue.');
            }
        }

        return $next($request);
    }
}
```

## User Flow

### New User Registration (Mandatory Passkey)
1. User visits `/register`
2. Enters name and email
3. Account is created and user is logged in
4. Redirected to `/passkey/register` with `GuestLayout` (clean UI, no dashboard)
5. User sees **mandatory** passkey registration form with warning
6. **Must complete WebAuthn flow** - no skip option
7. After successful registration → Auto-redirect to `/budgets`
8. If user tries to access any other route without passkey → Redirected back to registration

### Existing User Adding Passkey
1. User navigates to Settings → Passkeys (or `/passkey/register`)
2. Page uses `AuthenticatedLayout` (full dashboard/sidebar)
3. Can see existing passkeys (if any)
4. Register additional passkey
5. Success message shown, stays on page

## Testing Checklist
- [ ] New user registration flow shows clean UI (no dashboard)
- [ ] Passkey registration works and saves to database
- [ ] Registered passkeys appear in trusted devices list
- [ ] "Skip for now" button works for new users
- [ ] Auto-redirect after successful registration works
- [ ] Existing users see full dashboard when adding passkeys
- [ ] Can add multiple passkeys to same account
- [ ] Passkeys work for login after registration

## Production Debugging

If passkeys aren't showing up in production:

1. **Check WebAuthn credentials table:**
```sql
SELECT * FROM webauthn_credentials WHERE user_id = ?;
```

2. **Check browser console** for WebAuthn errors

3. **Verify HTTPS:** WebAuthn requires HTTPS in production

4. **Check domain configuration** in `config/webauthn.php`:
```php
'relying_party' => [
    'name' => env('APP_NAME'),
    'id' => env('WEBAUTHN_ID', parse_url(env('APP_URL'), PHP_URL_HOST)),
],
```

5. **Verify user has credentials:**
```php
$user->webAuthnCredentials()->count();
```

