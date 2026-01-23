<?php

namespace App\Services\Chat\ContextBuilders;

use App\Contracts\ContextBuilderInterface;
use App\Models\Budget;
use App\Models\User;

class AccountDetailsContextBuilder implements ContextBuilderInterface
{
    /**
     * Build detailed account context for liabilities (credit cards, loans, etc.).
     */
    public function build(User $user, Budget $budget, array $options = []): array
    {
        $liabilityAccounts = $budget->accounts()
            ->with(['plaidAccount.statementHistory'])
            ->get()
            ->filter(fn($account) => $account->isLiability())
            ->map(function ($account) {
                $plaid = $account->plaidAccount;
                
                $details = [
                    'name' => $account->name,
                    'type' => $account->type,
                    'balance' => abs($account->current_balance_cents / 100),
                    'institution' => $account->institution_name,
                ];

                // Add liability-specific details if available from Plaid
                if ($plaid) {
                    if ($plaid->apr) {
                        $details['apr'] = $plaid->apr;
                    }
                    if ($plaid->minimum_payment_cents) {
                        $details['minimum_payment'] = $plaid->minimum_payment_cents / 100;
                    }
                    if ($plaid->next_payment_due_date) {
                        $details['due_date'] = $plaid->next_payment_due_date;
                    }
                    if ($plaid->last_statement_balance_cents) {
                        $details['statement_balance'] = $plaid->last_statement_balance_cents / 100;
                    }
                    if ($plaid->last_statement_date) {
                        $details['statement_date'] = $plaid->last_statement_date;
                    }
                    if ($plaid->credit_limit_cents) {
                        $details['credit_limit'] = $plaid->credit_limit_cents / 100;
                        $details['available_credit'] = ($plaid->credit_limit_cents - abs($account->current_balance_cents)) / 100;
                        $details['utilization_percent'] = round((abs($account->current_balance_cents) / $plaid->credit_limit_cents) * 100, 1);
                    }
                }

                // Add autopay info
                if ($account->autopay_enabled) {
                    $details['autopay_enabled'] = true;
                    $details['autopay_source'] = $account->autopaySourceAccount?->name;
                }

                return $details;
            })
            ->values()
            ->toArray();

        return [
            'liability_accounts' => $liabilityAccounts,
            'total_debt' => collect($liabilityAccounts)->sum('balance'),
            'accounts_with_apr' => collect($liabilityAccounts)->filter(fn($a) => isset($a['apr']))->count(),
        ];
    }

    /**
     * Get the context type identifier.
     */
    public function getContextType(): string
    {
        return 'account_details';
    }

    /**
     * Estimate token count.
     */
    public function getTokenEstimate(Budget $budget): int
    {
        $liabilityCount = $budget->accounts()
            ->get()
            ->filter(fn($a) => $a->isLiability())
            ->count();

        // ~80 tokens per liability account + 20 for summary
        return ($liabilityCount * 80) + 20;
    }
}
