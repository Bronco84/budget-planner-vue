<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Scoping Plaid transaction ids to their account (2026_11_02_000039) left
 * plaid_transaction_id without an index of its own, yet Transaction::plaidTransaction()
 * joins on that column alone, and on MySQL the account_id foreign key lost its
 * implicit index to the new composite unique. Give both columns a plain index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plaid_transactions', function (Blueprint $table) {
            if (! Schema::hasIndex('plaid_transactions', ['plaid_transaction_id'])) {
                $table->index('plaid_transaction_id');
            }

            if (! Schema::hasIndex('plaid_transactions', ['account_id'])) {
                $table->index('account_id');
            }
        });
    }

    /**
     * The composite (account_id, plaid_transaction_id) unique keeps serving the
     * foreign key once the account_id index is gone.
     */
    public function down(): void
    {
        Schema::table('plaid_transactions', function (Blueprint $table) {
            if (Schema::hasIndex('plaid_transactions', 'plaid_transactions_plaid_transaction_id_index')) {
                $table->dropIndex('plaid_transactions_plaid_transaction_id_index');
            }

            if (Schema::hasIndex('plaid_transactions', 'plaid_transactions_account_id_index')) {
                $table->dropIndex('plaid_transactions_account_id_index');
            }
        });
    }
};
