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
        Schema::table('transactions', function (Blueprint $table) {
            // Change plaid_transaction_id from unsignedBigInteger to string
            // Plaid uses string IDs like 'ZOgdakRx3bux6KokjzQZIX1qyMD3X8HVKQ36J'
            $table->string('plaid_transaction_id')->nullable()->change();
            
            // Add index for better query performance
            $table->index('plaid_transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Drop the index first
            $table->dropIndex(['plaid_transaction_id']);
            
            // Revert back to unsignedBigInteger (though data may be lost)
            $table->unsignedBigInteger('plaid_transaction_id')->nullable()->change();
        });
    }
};
