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
            // First drop any foreign key constraints if they exist
            if (Schema::hasColumn('transactions', 'plaid_transaction_id')) {
                $table->dropColumn('plaid_transaction_id');
            }
            
            // Add the column back as a string type with adequate length for Plaid IDs
            $table->string('plaid_transaction_id', 100)->nullable()->after('date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'plaid_transaction_id')) {
                $table->dropColumn('plaid_transaction_id');
            }
            
            // Add it back as the original type
            $table->unsignedBigInteger('plaid_transaction_id')->nullable()->after('date');
        });
    }
};
