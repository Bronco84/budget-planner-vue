<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The same Plaid Item can be synced into more than one budget (linked again after
 * an unlink elsewhere), and both budgets then receive the same Plaid transaction
 * ids. Keep one raw row per local account: replace the global unique index on
 * plaid_transaction_id with one on (account_id, plaid_transaction_id). Loosening
 * a unique constraint cannot fail on existing rows. The account_id foreign key
 * keeps its own index, so neither index change touches it.
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
     */
    public function down(): void
    {
        Schema::table('plaid_transactions', function (Blueprint $table) {
            $table->unique('plaid_transaction_id');
        });

        Schema::table('plaid_transactions', function (Blueprint $table) {
            $table->dropUnique('plaid_transactions_account_plaid_id_unique');
        });
    }
};
