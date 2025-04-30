<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Ensure the transactions table has a proper foreign key to plaid_transactions if needed
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'plaid_transaction_id')) {
                $table->string('plaid_transaction_id')->nullable()->after('id')->index();
            }
        });
        
        // Add foreign key constraint if it doesn't exist already
        Schema::table('plaid_transactions', function (Blueprint $table) {
            // Add foreign key constraint to account_id if it doesn't exist
            if (!Schema::hasColumn('plaid_transactions', 'account_id_foreign')) {
                $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove the foreign key constraint
        Schema::table('plaid_transactions', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
        });
        
        // We don't need to drop the plaid_transaction_id column from transactions
        // as it might be used elsewhere
    }
}; 