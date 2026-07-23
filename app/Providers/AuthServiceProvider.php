<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\Budget;
use App\Models\CalendarConnection;
use App\Models\Transaction;
use App\Policies\AccountPolicy;
use App\Policies\BudgetPolicy;
use App\Policies\CalendarConnectionPolicy;
use App\Policies\TransactionPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Budget::class => BudgetPolicy::class,
        Account::class => AccountPolicy::class,
        Transaction::class => TransactionPolicy::class,
        CalendarConnection::class => CalendarConnectionPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
    }
}
