<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The PlaidConnection model now casts `access_token` as `encrypted`, so the
     * column must (1) be wide enough to hold ciphertext and (2) have any existing
     * plaintext tokens encrypted in place. Reads/writes go through the query
     * builder here to bypass the Eloquent cast (which would try to decrypt the
     * still-plaintext values and fail).
     */
    public function up(): void
    {
        // 1. Widen the column — Laravel's encrypted values are far longer than 255.
        Schema::table('plaid_connections', function (Blueprint $table) {
            $table->text('access_token')->change();
        });

        // 2. Encrypt any existing plaintext tokens in place.
        DB::table('plaid_connections')
            ->select('id', 'access_token')
            ->whereNotNull('access_token')
            ->orderBy('id')
            ->each(function ($row) {
                if ($row->access_token === null || $row->access_token === '') {
                    return;
                }

                // Skip values that already decrypt cleanly (idempotent / re-run safe).
                try {
                    Crypt::decryptString($row->access_token);

                    return;
                } catch (Throwable $e) {
                    // Not encrypted yet — fall through and encrypt it.
                }

                DB::table('plaid_connections')
                    ->where('id', $row->id)
                    ->update(['access_token' => Crypt::encryptString($row->access_token)]);
            });
    }

    /**
     * Reverse the migrations.
     *
     * Decrypt tokens back to plaintext and narrow the column. Best-effort:
     * values that no longer decrypt are left as-is.
     */
    public function down(): void
    {
        DB::table('plaid_connections')
            ->select('id', 'access_token')
            ->whereNotNull('access_token')
            ->orderBy('id')
            ->each(function ($row) {
                if ($row->access_token === null || $row->access_token === '') {
                    return;
                }

                try {
                    $plain = Crypt::decryptString($row->access_token);
                } catch (Throwable $e) {
                    return;
                }

                DB::table('plaid_connections')
                    ->where('id', $row->id)
                    ->update(['access_token' => $plain]);
            });

        Schema::table('plaid_connections', function (Blueprint $table) {
            $table->string('access_token')->change();
        });
    }
};
