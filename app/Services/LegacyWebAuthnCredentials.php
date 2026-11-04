<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The laragear/webauthn `webauthn_credentials` table, kept read-only after the
 * move to laravel/passkeys (2026_11_04_000049) so that migration can be rolled
 * back. Nothing signs in with these rows any more; they are only pruned, so a
 * passkey removed now does not come back on a rollback and a deleted account
 * leaves no public keys behind.
 */
class LegacyWebAuthnCredentials
{
    private const TABLE = 'webauthn_credentials';

    public static function deleteForUser(User $user): void
    {
        DB::table(self::TABLE)
            ->whereIn('authenticatable_type', array_unique([User::class, $user->getMorphClass()]))
            ->where('authenticatable_id', $user->getKey())
            ->delete();
    }

    /**
     * @param  string  $credentialId  base64url, as laravel/passkeys stores it
     */
    public static function deleteByCredentialId(string $credentialId): void
    {
        DB::table(self::TABLE)->where('id', $credentialId)->delete();
    }
}
