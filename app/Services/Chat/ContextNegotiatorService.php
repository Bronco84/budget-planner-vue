<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class ContextNegotiatorService
{
    /**
     * Available context types that can be requested by the AI.
     */
    public const AVAILABLE_CONTEXT_TYPES = [
        'accounts' => 'Account names, types, and current balances',
        'account_details' => 'Credit card APRs, minimum payments, due dates, statement balances',
        'transactions_recent' => 'Last 30 days of transactions',
        'transactions_month' => 'All transactions this month',
        'transactions_by_category' => 'Spending breakdown by category',
        'categories' => 'Budget allocations and remaining amounts',
        'statistics_monthly' => 'Income vs expenses this month with category breakdown',
        'statistics_yearly' => 'Year-to-date financial trends and comparisons',
        'recurring' => 'Recurring bills and scheduled transactions',
        'projections' => 'Projected future balances, upcoming transactions, cash flow forecasts',
        'goals' => 'Financial goals and progress',
        'payoff_plans' => 'Debt payoff strategies and projections',
        'investments' => 'Investment portfolio holdings and values',
        'properties' => 'Real estate holdings and linked mortgages',
    ];

    /**
     * Default context types to include if negotiation fails.
     */
    public const FALLBACK_CONTEXT_TYPES = [
        'accounts',
        'transactions_recent',
        'recurring',
    ];

    /**
     * Negotiate with the AI to determine what context is needed for the user's question.
     *
     * @param string $message The user's question
     * @return array List of context types needed
     */
    public function negotiate(string $message): array
    {
        try {
            $systemPrompt = $this->buildNegotiationPrompt();
            $userPrompt = "User question: \"{$message}\"";

            $response = Prism::text()
                ->using('openai', 'gpt-4o-mini')
                ->withMessages([
                    new SystemMessage($systemPrompt),
                    new UserMessage($userPrompt),
                ])
                ->generate();

            $requestedTypes = $this->parseNegotiationResponse($response->text);

            Log::info('Context negotiation completed', [
                'message_preview' => substr($message, 0, 100),
                'requested_types' => $requestedTypes,
            ]);

            return $requestedTypes;
        } catch (\Exception $e) {
            Log::error('Context negotiation failed, using fallback', [
                'error' => $e->getMessage(),
            ]);

            return self::FALLBACK_CONTEXT_TYPES;
        }
    }

    /**
     * Build the system prompt for context negotiation.
     */
    protected function buildNegotiationPrompt(): string
    {
        $contextList = '';
        foreach (self::AVAILABLE_CONTEXT_TYPES as $type => $description) {
            $contextList .= "- {$type}: {$description}\n";
        }

        return <<<PROMPT
You are a context advisor for a financial assistant. Your job is to determine what financial data would help answer a user's question accurately.

Given a user's question, select the minimum context types needed for an accurate answer.

Available context types:
{$contextList}

Guidelines:
- Select only what's necessary - don't request everything
- For questions about spending, you likely need transactions and/or statistics
- For questions about balances or account status, you need accounts
- For questions about budgets, you need categories
- For questions about future/projected balances, you need projections
- For debt-related questions, you may need account_details and/or payoff_plans
- For investment questions, you need investments
- For general advice without specific data needs, you can return an empty array

Respond with ONLY a valid JSON array of context type keys, e.g.:
["transactions_recent", "categories", "statistics_monthly"]

If no specific data is needed, respond with:
[]
PROMPT;
    }

    /**
     * Parse the AI's response to extract context types.
     *
     * @param string $response The AI's response text
     * @return array List of valid context types
     */
    protected function parseNegotiationResponse(string $response): array
    {
        // Clean up the response - remove markdown code blocks if present
        $cleaned = trim($response);
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        $cleaned = trim($cleaned);

        // Try to parse as JSON
        $decoded = json_decode($cleaned, true);

        if (!is_array($decoded)) {
            Log::warning('Failed to parse negotiation response as JSON', [
                'response' => $response,
            ]);
            return self::FALLBACK_CONTEXT_TYPES;
        }

        // Filter to only valid context types
        $validTypes = array_filter($decoded, function ($type) {
            return is_string($type) && array_key_exists($type, self::AVAILABLE_CONTEXT_TYPES);
        });

        return array_values($validTypes);
    }

    /**
     * Get all available context types with descriptions.
     *
     * @return array
     */
    public function getAvailableContextTypes(): array
    {
        return self::AVAILABLE_CONTEXT_TYPES;
    }
}
