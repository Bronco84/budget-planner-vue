<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nova access used to be granted to a hard-coded email address in the viewNova
 * gate as well as to users with is_admin. The gate now checks is_admin only, so
 * this grants is_admin to that previously allowlisted account (if it exists)
 * to keep the owner's Nova access across the deploy.
 */
return new class extends Migration
{
    private const OWNER_EMAIL = 'bamccoley@gmail.com';

    public function up(): void
    {
        if (! Schema::hasColumn('users', 'is_admin')) {
            return;
        }

        DB::table('users')
            ->whereRaw('LOWER(email) = ?', [self::OWNER_EMAIL])
            ->update(['is_admin' => true]);
    }

    /**
     * Intentionally a no-op: revoking is_admin on rollback could lock the owner
     * out of Nova (the old allowlist may not be restored by the same rollback),
     * and we cannot tell whether the flag was already set before up() ran.
     */
    public function down(): void
    {
        //
    }
};
