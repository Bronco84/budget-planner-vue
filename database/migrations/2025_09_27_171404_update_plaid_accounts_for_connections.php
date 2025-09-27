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
        Schema::table('plaid_accounts', function (Blueprint $table) {
            // Add reference to PlaidConnection if it doesn't exist
            if (!Schema::hasColumn('plaid_accounts', 'plaid_connection_id')) {
                $table->foreignId('plaid_connection_id')->nullable()->after('id')->constrained()->onDelete('cascade');
            }
            
            // Add account-specific fields if they don't exist
            if (!Schema::hasColumn('plaid_accounts', 'account_name')) {
                $table->string('account_name')->after('plaid_account_id');
            }
            if (!Schema::hasColumn('plaid_accounts', 'account_type')) {
                $table->string('account_type')->nullable()->after('account_name');
            }
            if (!Schema::hasColumn('plaid_accounts', 'account_subtype')) {
                $table->string('account_subtype')->nullable()->after('account_type');
            }
            if (!Schema::hasColumn('plaid_accounts', 'account_mask')) {
                $table->string('account_mask')->nullable()->after('account_subtype');
            }
        });
        
        // Drop foreign key constraints and columns in separate step
        Schema::table('plaid_accounts', function (Blueprint $table) {
            // Drop foreign key constraints first
            if (Schema::hasColumn('plaid_accounts', 'budget_id')) {
                $table->dropForeign(['budget_id']);
            }
            
            // Remove fields that now belong to PlaidConnection
            $columnsToRemove = ['budget_id', 'plaid_item_id', 'institution_name', 'access_token', 'last_sync_at'];
            foreach ($columnsToRemove as $column) {
                if (Schema::hasColumn('plaid_accounts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plaid_accounts', function (Blueprint $table) {
            // Remove the new fields
            $table->dropColumn([
                'account_name',
                'account_type', 
                'account_subtype',
                'account_mask',
            ]);
            
            // Add back the old fields
            $table->foreignId('budget_id')->after('id')->constrained()->onDelete('cascade');
            $table->string('plaid_item_id')->after('plaid_account_id');
            $table->string('institution_name')->after('plaid_item_id');
            $table->string('access_token')->after('institution_name');
            $table->timestamp('last_sync_at')->nullable()->after('balance_updated_at');
            
            // Remove the PlaidConnection reference
            if (Schema::hasColumn('plaid_accounts', 'plaid_connection_id')) {
                $table->dropForeign(['plaid_connection_id']);
                $table->dropColumn('plaid_connection_id');
            }
        });
    }
};