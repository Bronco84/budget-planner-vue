# Passkey Production Debugging Guide

## Issue
Passkey registration shows success in frontend but credential is not saved to database in production.

## Critical Environment Variables

Check your production `.env` file for these settings:

```bash
# WebAuthn Configuration
WEBAUTHN_NAME="Budget Planner"
WEBAUTHN_ID=yourdomain.com  # CRITICAL: Must match your domain (without https://)
WEBAUTHN_ORIGINS=https://yourdomain.com  # Must include https://

# Examples:
# If your domain is "budget.example.com"
WEBAUTHN_ID=budget.example.com
WEBAUTHN_ORIGINS=https://budget.example.com

# If your domain is "example.com"  
WEBAUTHN_ID=example.com
WEBAUTHN_ORIGINS=https://example.com
```

## What to Check

### 1. Environment Configuration
```bash
# SSH into production server
php artisan config:cache
php artisan config:clear
php artisan config:cache

# Verify WebAuthn config is correct
php artisan tinker
> config('webauthn.relying_party')
# Should show your domain as 'id'

> config('webauthn.origins')
# Should show your full HTTPS URL
```

### 2. Database Table
```bash
# Check if web_authn_credentials table exists
php artisan tinker
> Schema::hasTable('web_authn_credentials')
# Should return true

# Check table structure
> DB::select('DESCRIBE web_authn_credentials')
```

### 3. Check Laravel Logs
```bash
# Watch logs in real-time
tail -f storage/logs/laravel.log

# Or check recent errors
tail -100 storage/logs/laravel.log | grep -i "passkey\|webauthn\|error"
```

### 4. Browser Console Logs
After deploying the latest changes, register a passkey and check browser console for:

```
Starting passkey registration...
Registration options received: {...}
Sending credential to server: {...}
Register response status: 204 (or 500 if error)
Register response ok: true/false
```

## Expected Laravel Log Entries

**On successful registration:**
```
Starting passkey registration - user_id: 1, email: your@email.com
Passkey credential saved - user_id: 1, credential_id: abc123
User now has passkeys - user_id: 1, passkey_count: 1
Passkey registered and trusted device created successfully
```

**On failure:**
```
Passkey registration failed - user_id: 1, error: [error message], trace: [...]
```

## Common Issues

### Issue 1: WEBAUTHN_ID Not Set
**Symptom:** Registration silently fails, no error in logs
**Fix:** Set `WEBAUTHN_ID=yourdomain.com` in production `.env`

### Issue 2: Origin Mismatch
**Symptom:** "Origin mismatch" error in logs
**Fix:** Ensure `WEBAUTHN_ORIGINS` matches your production URL exactly (with https://)

### Issue 3: HTTP Instead of HTTPS
**Symptom:** WebAuthn doesn't work at all
**Fix:** WebAuthn requires HTTPS in production. Ensure SSL is configured.

### Issue 4: Session Issues
**Symptom:** User is authenticated but `Auth::check()` returns false in controller
**Fix:** 
- Check session driver is working (`SESSION_DRIVER=database` recommended)
- Verify cookies are being sent with requests
- Check `SESSION_DOMAIN` and `SESSION_SECURE_COOKIE` settings

### Issue 5: Database Migration Not Run
**Symptom:** Table doesn't exist error
**Fix:** 
```bash
php artisan migrate
```

## Manual Testing Steps

1. **Clear everything:**
   ```bash
   php artisan cache:clear
   php artisan config:clear
   php artisan route:clear
   php artisan view:clear
   php artisan config:cache
   ```

2. **Test authentication:**
   ```bash
   php artisan tinker
   > Auth::user()
   # Should show user object if logged in via session
   ```

3. **Test credential creation manually:**
   ```bash
   php artisan tinker
   > $user = User::find(1)
   > $user->webAuthnCredentials()->create([
       'id' => 'test-credential-id',
       'user_id' => $user->id,
       'counter' => 0,
       'rp_id' => config('webauthn.relying_party.id'),
       'origin' => config('webauthn.origins'),
       'transports' => json_encode([]),
       'aaguid' => '00000000-0000-0000-0000-000000000000',
       'public_key' => 'test-key',
       'attestation_format' => 'none',
   ])
   ```
   If this fails, there's a database/model issue.

## Contact Points

After running these diagnostics, provide:
1. Laravel log output during registration attempt
2. Browser console output during registration
3. Output of `config('webauthn.relying_party')`
4. Output of `config('webauthn.origins')`
5. Your production domain name

