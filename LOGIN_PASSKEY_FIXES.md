# Login and Passkey Authentication Fixes

## Overview
Fixed multiple issues with passkey authentication, logout functionality, and CSRF token handling to ensure smooth login/logout flow.

## Issues Fixed

### 1. CSRF Token Mismatch on Passkey Login (419 Error)
**Problem:** Passkey login was failing with 419 CSRF token mismatch error.

**Root Cause:** Axios was not configured to automatically send CSRF tokens with requests.

**Solution:**
- Configured Axios in `resources/js/bootstrap.js` to automatically send CSRF tokens
- Added CSRF token refresh on Inertia page navigation
- Updated `PasskeyLogin.vue` to use `window.axios.post()` instead of `fetch()`

**Files Changed:**
- `resources/js/bootstrap.js`
- `resources/js/Pages/Auth/PasskeyLogin.vue`

### 2. Auto-Login After Logout
**Problem:** After logging out, refreshing the page would automatically log the user back in.

**Root Cause:** The `RememberDevice` middleware was running on the logout route, immediately creating a new session.

**Solution:**
- Modified `RememberDevice` middleware to skip execution on logout route
- Updated `PasskeyAuthController@destroy` to explicitly delete trusted device token from database before forgetting cookie

**Files Changed:**
- `app/Http/Middleware/RememberDevice.php`
- `app/Http/Controllers/PasskeyAuthController.php`

### 3. SQL Error on Passkey Login (Column 'rawId' not found)
**Problem:** Login failed with SQL error about missing `rawId` column.

**Root Cause:** The `config/auth.php` was using the default `eloquent` driver instead of the WebAuthn-specific driver.

**Solution:**
- Changed the `users` provider driver from `eloquent` to `eloquent-webauthn`

**Files Changed:**
- `config/auth.php`

### 4. 419 Error on Logout After Login
**Problem:** After successful login, clicking logout would return a 419 Page Expired error.

**Root Cause:** Post-login redirect using `router.visit()` didn't always refresh the CSRF token for subsequent requests.

**Solution:**
- Changed post-login redirect in `PasskeyLogin.vue` to use `window.location.href` for full page reload
- This ensures fresh session and CSRF token

**Files Changed:**
- `resources/js/Pages/Auth/PasskeyLogin.vue`

### 5. 419 Error on First Login Attempt After Logout
**Problem:** After logout, returning to login page and clicking sign in would fail with 419 on first attempt, but work after refresh.

**Root Cause:** Inertia's client-side navigation didn't update Axios's CSRF token header when navigating back to login.

**Solution:**
- Added `router.on('navigate', ...)` handler in `bootstrap.js` to refresh Axios CSRF token on every Inertia navigation

**Files Changed:**
- `resources/js/bootstrap.js`

### 6. 419 Error on Logout Immediately After Login
**Problem:** Clicking logout immediately after logging in would return 419 error.

**Root Cause:** Logout was using Inertia's `Link` component which does client-side navigation, not always refreshing CSRF token.

**Solution:**
- Replaced `DropdownLink` in logout with a regular form submission
- Added `logout()` method that creates and submits a form with fresh CSRF token

**Files Changed:**
- `resources/js/Layouts/AuthenticatedLayout.vue`

## Key Technical Changes

### Axios CSRF Configuration
```javascript
// Configure Axios to automatically send CSRF tokens
let token = document.head.querySelector('meta[name="csrf-token"]');
if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
}
window.axios.defaults.withCredentials = true;

// Refresh CSRF token on Inertia navigation
router.on('navigate', () => {
    let token = document.head.querySelector('meta[name="csrf-token"]');
    if (token) {
        window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
    }
});
```

### Auth Provider Configuration
```php
'providers' => [
    'users' => [
        'driver' => 'eloquent-webauthn', // Changed from 'eloquent'
        'model' => App\Models\User::class,
    ],
],
```

### Logout Form Submission
```javascript
const logout = () => {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/logout';
    
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (csrfToken) {
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = '_token';
        csrfInput.value = csrfToken;
        form.appendChild(csrfInput);
    }
    
    document.body.appendChild(form);
    form.submit();
};
```

## Testing
All login/logout flows now work correctly:
- ✅ Passkey login
- ✅ Logout after login
- ✅ No auto-login after logout
- ✅ Login immediately after logout
- ✅ Multiple login/logout cycles
- ✅ CSRF tokens properly managed across all flows

## Notes
- All changes maintain backward compatibility
- No database migrations required (auth provider change is configuration only)
- Login page UI improvements included (modern gradient background, glass morphism, app logo)

