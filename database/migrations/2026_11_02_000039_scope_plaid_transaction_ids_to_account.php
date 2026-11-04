<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The same Plaid Item can be synced into more than one budget (linked again after
 * an unlink elsewhere), and both budgets then receive the same Plaid transaction
 * ids. Keep one raw row per local account: replace the global unique index on
 * plaid_transaction_id with one on (account_id, plaid_transaction_id). Loosening
 * a unique constraint cannot fail on existing rows.
 *
 * On MySQL the new composite index can serve the account_id foreign key, so MySQL
 * silently drops the foreign key's own implicit index, and plaid_transaction_id
 * loses the only index that led with it. The follow-up migration
 * 2026_11_03_000057_index_plaid_transaction_lookups adds both indexes back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plaid_transactions', function (Blueprint $table) {
            $table->unique(['account_id', 'plaid_transaction_id'], 'plaid_transactions_account_plaid_id_unique');
        });

        Schema::table('plaid_transactions', function (Blueprint $table) {
            $table->dropUnique('plaid_transactions_plaid_transaction_id_unique');
        });
    }

    /**
     * Rolling back fails if the same Plaid transaction is now stored for more than
     * one account; remove those duplicates first.
     *
     * MySQL refuses to drop the composite unique while it is the only index able
     * to serve the account_id foreign key (error 1553), so give the key an index
     * of its own first.
     */
    public function down(): void
    {
        Schema::table('plaid_transactions', function (Blueprint $table) {
            $table->unique('plaid_transaction_id');

            if (! Schema::hasIndex('plaid_transactions', ['account_id'])) {
                $table->index('account_id');
            }
        });

        Schema::table('plaid_transactions', function (Blueprint $table) {
            $table->dropUnique('plaid_transactions_account_plaid_id_unique');
        });
    }
};
