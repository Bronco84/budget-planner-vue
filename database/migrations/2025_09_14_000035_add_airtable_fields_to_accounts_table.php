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
        Schema::table('accounts', function (Blueprint $table) {
            // Add Airtable synchronization fields
            $table->string('airtable_account_id')->nullable()->after('include_in_budget');
            $table->json('airtable_metadata')->nullable()->after('airtable_account_id');
            $table->timestamp('last_airtable_sync')->nullable()->after('airtable_metadata');
            
            // Add indexes for performance
            $table->index('airtable_account_id');
            $table->index(['budget_id', 'airtable_account_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Remove indexes
            $table->dropIndex(['airtable_account_id']);
            $table->dropIndex(['budget_id', 'airtable_account_id']);
            
            // Remove columns
            $table->dropColumn([
                'airtable_account_id',
                'airtable_metadata', 
                'last_airtable_sync'
            ]);
        });
    }
};
