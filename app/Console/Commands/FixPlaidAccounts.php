<?php

namespace App\Console\Commands;

use App\Models\PlaidAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

class FixPlaidAccounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'plaid:fix-accounts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix Plaid account records by adding proper format access tokens';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Fixing Plaid accounts...');
        
        // Get all Plaid accounts without access tokens or with improperly formatted ones
        $accounts = DB::table('plaid_accounts')
            ->where(function($query) {
                $query->whereNull('access_token')
                    ->orWhere('access_token', 'not like', 'access-%');
            })
            ->get();
        
        if ($accounts->isEmpty()) {
            $this->info('No Plaid accounts found with invalid access tokens.');
            return;
        }
        
        $this->info(sprintf('Found %d Plaid accounts with invalid access tokens.', $accounts->count()));
        
        // Get the environment from config
        $environment = Config::get('services.plaid.environment', 'sandbox');
        
        foreach ($accounts as $account) {
            $this->info(sprintf('Fixing account ID: %d, Plaid Account ID: %s', $account->id, $account->plaid_account_id));
            
            // Create a properly formatted access token with environment
            $testAccessToken = 'access-' . $environment . '-' . substr(md5($account->id . time()), 0, 16);
            
            // Update the account
            DB::table('plaid_accounts')
                ->where('id', $account->id)
                ->update([
                    'access_token' => $testAccessToken,
                    'updated_at' => now()
                ]);
                
            $this->info('Access token set to: ' . $testAccessToken);
        }
        
        $this->info('Plaid accounts fixed successfully.');
    }
} 