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
            // Add missing Airtable fields that weren't in the previous migration
            $table->string('airtable_account_id')->nullable()->after('airtable_transaction_id');
            $table->json('airtable_metadata')->nullable()->after('is_airtable_imported');
            $table->string('computed_account_name')->nullable()->after('airtable_metadata');
            
            // Make account_id nullable since we'll use Airtable accounts
            $table->integer('account_id')->nullable()->change();
            
            // Add indexes
            $table->index('airtable_account_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Remove indexes
            $table->dropIndex(['airtable_account_id']);
            
            // Remove columns
            $table->dropColumn([
                'airtable_account_id', 
                'airtable_metadata', 
                'computed_account_name'
            ]);
            
            // Note: We don't restore account_id as NOT NULL to avoid data loss
        });
    }
};
