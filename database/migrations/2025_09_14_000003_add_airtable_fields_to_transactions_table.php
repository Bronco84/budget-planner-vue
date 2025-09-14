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
            // Add Airtable integration fields
            $table->string('airtable_transaction_id')->nullable()->after('plaid_transaction_id');
            $table->boolean('is_airtable_imported')->default(false)->after('is_plaid_imported');
            
            // Add index for Airtable transaction ID
            $table->index('airtable_transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['airtable_transaction_id']);
            $table->dropColumn(['airtable_transaction_id', 'is_airtable_imported']);
        });
    }
};
