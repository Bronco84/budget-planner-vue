<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The CalendarConnection model now casts `access_token` and `refresh_token`
     * as `encrypted`, so existing plaintext Google tokens are encrypted in place.
     * Both columns were created as `text`, which already holds the ciphertext,
     * so unlike the Plaid tokens (2026_07_23_000000) no widening is needed.
     *
     * Reads and writes go through the query builder to bypass the Eloquent
     * cast, which would fail to decrypt the still-plaintext values.
     */
    public function up(): void
    {
        $this->rewriteTokens(function (string $value): ?string {
            // Already encrypted (re-run safe): leave it.
            try {
                Crypt::decryptString($value);

                return null;
            } catch (Throwable $e) {
                return Crypt::encryptString($value);
            }
        });
    }

    /**
     * Decrypt the tokens back to plaintext. Values that do not decrypt (already
     * plaintext, or encrypted under another key) are left as they are.
     */
    public function down(): void
    {
        $this->rewriteTokens(function (string $value): ?string {
            try {
                return Crypt::decryptString($value);
            } catch (Throwable $e) {
                return null;
            }
        });
    }

    /**
     * Apply $transform to every non-null token; a null result leaves it unchanged.
     *
     * @param  callable(string): ?string  $transform
     */
    private function rewriteTokens(callable $transform): void
    {
        DB::table('calendar_connections')
            ->select('id', 'access_token', 'refresh_token')
            ->lazyById()
            ->each(function ($row) use ($transform) {
                $changes = [];

                foreach (['access_token', 'refresh_token'] as $column) {
                    $value = $row->{$column};

                    // An empty string is encrypted too: the cast cannot decrypt ''.
                    if ($value === null) {
                        continue;
                    }

                    $new = $transform($value);

                    if ($new !== null) {
                        $changes[$column] = $new;
                    }
                }

                if ($changes !== []) {
                    DB::table('calendar_connections')->where('id', $row->id)->update($changes);
                }
            });
    }
};
