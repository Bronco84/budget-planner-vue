<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, migrate existing PlaidAccount data to the new structure
        $this->migrateExistingPlaidAccounts();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration is not easily reversible since it consolidates data
        // You would need to manually restore the old structure if needed
    }
    
    private function migrateExistingPlaidAccounts(): void
    {
        // Check if we need to migrate (if old columns still exist)
        if (!Schema::hasColumn('plaid_accounts', 'budget_id') || 
            !Schema::hasColumn('plaid_accounts', 'plaid_item_id')) {
            echo "Migration not needed - columns already removed or data already migrated.\n";
            return;
        }
        
        // Get all existing PlaidAccount records grouped by institution
        $existingPlaidAccounts = DB::table('plaid_accounts')->get();
        
        if ($existingPlaidAccounts->isEmpty()) {
            return; // No data to migrate
        }
        
        // Group by budget_id + plaid_item_id to create PlaidConnections
        $connectionGroups = $existingPlaidAccounts->groupBy(function ($account) {
            return $account->budget_id . '_' . $account->plaid_item_id;
        });
        
        foreach ($connectionGroups as $groupKey => $accounts) {
            $firstAccount = $accounts->first();
            
            // Create PlaidConnection record
            $connectionId = DB::table('plaid_connections')->insertGetId([
                'budget_id' => $firstAccount->budget_id,
                'plaid_item_id' => $firstAccount->plaid_item_id,
                'institution_id' => null, // We don't have this from old data
                'institution_name' => $firstAccount->institution_name,
                'access_token' => $firstAccount->access_token,
                'status' => 'active',
                'error_message' => null,
                'last_sync_at' => $firstAccount->last_sync_at,
                'created_at' => $firstAccount->created_at,
                'updated_at' => $firstAccount->updated_at,
            ]);
            
            // Update each PlaidAccount to reference the new PlaidConnection
            foreach ($accounts as $account) {
                DB::table('plaid_accounts')
                    ->where('id', $account->id)
                    ->update([
                        'plaid_connection_id' => $connectionId,
                        'account_name' => 'Unknown Account', // We don't have this from old data
                        'account_type' => null, // We don't have this from old data
                        'account_subtype' => null, // We don't have this from old data  
                        'account_mask' => null, // We don't have this from old data
                    ]);
            }
        }
        
        echo "Migrated " . $connectionGroups->count() . " Plaid connections and " . $existingPlaidAccounts->count() . " accounts.\n";
    }
};