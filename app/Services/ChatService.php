<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\FlaggedChatMessage;
use App\Models\User;
use App\Services\Chat\ContextNegotiatorService;
use App\Services\Chat\SmartContextLoaderService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Illuminate\Support\Facades\Log;

class ChatService
{
    public function __construct(
        protected ContentModerationService $moderationService,
        protected ContextNegotiatorService $contextNegotiator,
        protected SmartContextLoaderService $contextLoader
    ) {}

    public function processMessageStream(
        User $user,
        string $message,
        ?int $conversationId = null,
        ?int $budgetId = null
    ) {
        try {
            // 1. Check if user is banned
            if ($this->moderationService->isUserBanned($user)) {
                return response()->json([
                    'error' => 'You have been temporarily restricted from using chat. Please contact support if you believe this is an error.',
                    'success' => false,
                    'banned' => true,
                ], 403);
            }

            // 2. Check rate limits
            $rateLimit = $this->moderationService->checkRateLimit($user);
            if (!$rateLimit['allowed']) {
                return response()->json([
                    'error' => 'Rate limit exceeded. Please try again later.',
                    'success' => false,
                    'rate_limit' => $rateLimit,
                ], 429);
            }

            // 3. Sanitize input
            $message = $this->moderationService->sanitizeContent($message);

            // 4. Check content for malicious patterns
            $contentCheck = $this->moderationService->checkContent($message, $user);
            if (!$contentCheck['safe']) {
                // Get or create conversation for logging
                $conversation = $this->getOrCreateConversation($user, $conversationId);

                // Log flagged content to database
                $this->flagMessage($user, $conversation, $message, $contentCheck);

                // Handle based on severity
                if ($contentCheck['action'] === 'block') {
                    return response()->json([
                        'error' => 'Your message was blocked because it violates our usage policies. Please rephrase your question and try again.',
                        'success' => false,
                        'blocked' => true,
                    ], 400);
                }
            }

            // 5. Get or create conversation
            $conversation = $this->getOrCreateConversation($user, $conversationId);

            // 6. Track conversation activity
            $this->moderationService->trackConversationActivity($user, $conversation->id);

            // 7. Store user message
            $userMessage = $this->storeMessage($conversation, 'user', $message);

            // 8. Phase 1: Context Negotiation - Ask AI what context it needs
            $requestedContextTypes = $this->contextNegotiator->negotiate($message);

            Log::info('Context negotiation completed for streaming', [
                'user_id' => $user->id,
                'message_preview' => substr($message, 0, 50),
                'requested_types' => $requestedContextTypes,
            ]);

            // 9. Phase 2: Load requested context with PII scrubbing
            $context = $this->contextLoader->load($user, $budgetId, $requestedContextTypes);

            // 10. Get conversation history
            $conversationHistory = $this->getConversationHistory($conversation);

            // 11. Build system prompt with context and safety guidelines
            $baseSystemPrompt = $this->buildSmartSystemPrompt($context, $requestedContextTypes);
            $systemPrompt = $this->moderationService->buildSafeSystemPrompt($baseSystemPrompt);

            // 12. Prepend system message to conversation history
            $messages = array_merge(
                [new SystemMessage($systemPrompt)],
                $conversationHistory
            );

            // 13. Use simulated streaming with Prism generate()
            Log::info('Starting simulated streaming response', [
                'user_id' => $user->id,
                'conversation_id' => $conversation->id,
                'message_count' => count($messages),
            ]);

            $conversationId = $conversation->id;

            // Create a simulated streaming response
            return response()->stream(function () use ($messages, $conversationId) {
                // Send conversation_id event first
                echo "event: conversation_id\n";
                echo 'data: ' . json_encode(['conversation_id' => $conversationId]) . "\n\n";
                ob_flush();
                flush();

                try {
                    // Get the full response from Prism
                    $response = Prism::text()
                        ->using('openai', 'gpt-4o-mini')
                        ->withMessages($messages)
                        ->generate();

                    $fullText = $response->text;
                    
                    // Simulate streaming by sending chunks
                    $words = explode(' ', $fullText);
                    foreach ($words as $i => $word) {
                        $delta = ($i === 0) ? $word : ' ' . $word;
                        
                        echo "event: text_delta\n";
                        echo 'data: ' . json_encode(['delta' => $delta]) . "\n\n";
                        ob_flush();
                        flush();
                        
                        // Small delay to simulate real streaming
                        usleep(50000); // 50ms delay
                    }

                    // Send completion event
                    echo "event: stream_end\n";
                    echo 'data: ' . json_encode(['finish_reason' => 'complete']) . "\n\n";
                    ob_flush();
                    flush();

                } catch (\Exception $e) {
                    echo "event: error\n";
                    echo 'data: ' . json_encode(['error' => $e->getMessage()]) . "\n\n";
                    ob_flush();
                    flush();
                }
            }, 200, [
                'Cache-Control' => 'no-cache',
                'Content-Type' => 'text/event-stream',
                'X-Accel-Buffering' => 'no',
            ]);
        } catch (\Exception $e) {
            Log::error('Chat service streaming error', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to process message. Please try again.',
                'success' => false,
            ], 500);
        }
    }

    public function processMessage(
        User $user,
        string $message,
        ?int $conversationId = null,
        ?int $budgetId = null
    ): array {
        try {
            // 1. Check if user is banned
            if ($this->moderationService->isUserBanned($user)) {
                return [
                    'error' => 'You have been temporarily restricted from using chat. Please contact support if you believe this is an error.',
                    'success' => false,
                    'banned' => true,
                ];
            }

            // 2. Check rate limits
            $rateLimit = $this->moderationService->checkRateLimit($user);
            if (!$rateLimit['allowed']) {
                return [
                    'error' => 'Rate limit exceeded. Please try again later.',
                    'success' => false,
                    'rate_limit' => $rateLimit,
                ];
            }

            // 3. Sanitize input
            $message = $this->moderationService->sanitizeContent($message);

            // 4. Check content for malicious patterns
            $contentCheck = $this->moderationService->checkContent($message, $user);
            if (!$contentCheck['safe']) {
                // Get or create conversation for logging
                $conversation = $this->getOrCreateConversation($user, $conversationId);

                // Log flagged content to database
                $this->flagMessage($user, $conversation, $message, $contentCheck);

                // Handle based on severity
                if ($contentCheck['action'] === 'block') {
                    return [
                        'error' => 'Your message was blocked because it violates our usage policies. Please rephrase your question and try again.',
                        'success' => false,
                        'blocked' => true,
                    ];
                }
            }

            // 5. Get or create conversation
            $conversation = $this->getOrCreateConversation($user, $conversationId);

            // 6. Track conversation activity
            $this->moderationService->trackConversationActivity($user, $conversation->id);

            // 7. Store user message
            $userMessage = $this->storeMessage($conversation, 'user', $message);

            // 8. Phase 1: Context Negotiation - Ask AI what context it needs
            $requestedContextTypes = $this->contextNegotiator->negotiate($message);

            Log::info('Context negotiation completed', [
                'user_id' => $user->id,
                'message_preview' => substr($message, 0, 50),
                'requested_types' => $requestedContextTypes,
            ]);

            // 9. Phase 2: Load requested context with PII scrubbing
            $context = $this->contextLoader->load($user, $budgetId, $requestedContextTypes);

            // 10. Get conversation history
            $conversationHistory = $this->getConversationHistory($conversation);

            // 11. Build system prompt with context and safety guidelines
            $baseSystemPrompt = $this->buildSmartSystemPrompt($context, $requestedContextTypes);
            $systemPrompt = $this->moderationService->buildSafeSystemPrompt($baseSystemPrompt);

            // 12. Prepend system message to conversation history
            $messages = array_merge(
                [new SystemMessage($systemPrompt)],
                $conversationHistory
            );

            // 13. Call LLM via Prism
            $response = Prism::text()
                ->using('openai', 'gpt-4o-mini')
                ->withMessages($messages)
                ->generate();

            $assistantMessage = $response->text;

            // 13. Validate AI response
            $responseValidation = $this->moderationService->validateResponse($assistantMessage);
            if (!$responseValidation['safe']) {
                Log::error('AI response failed validation', [
                    'user_id' => $user->id,
                    'conversation_id' => $conversation->id,
                    'error' => $responseValidation['error'],
                ]);

                return [
                    'error' => 'Unable to generate a safe response. Please try rephrasing your question.',
                    'success' => false,
                ];
            }

            // 14. Store assistant response
            $this->storeMessage($conversation, 'assistant', $assistantMessage);

            // 15. Generate title for conversation if it's the first exchange
            if ($conversation->messages()->count() <= 2 && !$conversation->title) {
                $this->generateConversationTitle($conversation, $message);
            }

            return [
                'conversation_id' => $conversation->id,
                'message' => $assistantMessage,
                'success' => true,
                'rate_limit' => $rateLimit,
            ];
        } catch (\Exception $e) {
            Log::error('Chat service error', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'error' => 'Failed to process message. Please try again.',
                'success' => false,
            ];
        }
    }

    protected function flagMessage(User $user, ChatConversation $conversation, string $content, array $contentCheck): void
    {
        foreach ($contentCheck['flags'] as $flag) {
            FlaggedChatMessage::create([
                'user_id' => $user->id,
                'conversation_id' => $conversation->id,
                'flag_type' => $flag,
                'content' => substr($content, 0, 1000), // Limit stored content
                'action_taken' => $contentCheck['action'],
                'metadata' => [
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'all_flags' => $contentCheck['flags'],
                ],
            ]);
        }
    }

    protected function getOrCreateConversation(User $user, ?int $conversationId): ChatConversation
    {
        if ($conversationId) {
            $conversation = ChatConversation::where('user_id', $user->id)
                ->findOrFail($conversationId);
            return $conversation;
        }

        return ChatConversation::create([
            'user_id' => $user->id,
        ]);
    }

    protected function storeMessage(ChatConversation $conversation, string $role, string $content): ChatMessage
    {
        return ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role' => $role,
            'content' => $content,
        ]);
    }

    protected function getConversationHistory(ChatConversation $conversation): array
    {
        return $conversation->messages()
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($message) {
                return match ($message->role) {
                    'user' => new UserMessage($message->content),
                    'assistant' => new AssistantMessage($message->content),
                    default => new UserMessage($message->content),
                };
            })
            ->toArray();
    }

    public function buildContext(User $user, ?int $budgetId = null): array
    {
        $activeBudget = $budgetId
            ? Budget::where('user_id', $user->id)->find($budgetId)
            : $user->getActiveBudget();

        if (!$activeBudget) {
            return [
                'user' => [
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'budget' => null,
            ];
        }

        // Load relationships
        $activeBudget->load(['accounts', 'transactions', 'recurringTransactionTemplates']);

        // Calculate net worth: assets minus liabilities
        $totalBalance = $activeBudget->accounts
            ->filter(fn($account) => !$account->exclude_from_total_balance)
            ->sum(function ($account) {
                $balanceInDollars = $account->current_balance_cents / 100;
                // Liabilities (credit cards, mortgages, loans, etc.) are subtracted
                return $account->isLiability() ? -abs($balanceInDollars) : $balanceInDollars;
            });

        return [
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
            'budget' => [
                'id' => $activeBudget->id,
                'name' => $activeBudget->name,
                'total_balance' => $totalBalance,
                'account_count' => $activeBudget->accounts->count(),
            ],
            'accounts' => $activeBudget->accounts->map(fn($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->account_type,
                'balance' => $a->current_balance_cents / 100,
                'institution' => $a->institution_name,
            ])->toArray(),
            'recent_transactions' => $activeBudget->transactions()
                ->latest('date')
                ->limit(20)
                ->get()
                ->map(fn($t) => [
                    'date' => $t->date->format('Y-m-d'),
                    'description' => $t->description,
                    'amount' => $t->amount_in_cents / 100,
                    'category' => $t->category,
                    'account' => $t->account->name ?? 'Unknown',
                ])
                ->toArray(),
            'recurring_transactions' => $activeBudget->recurringTransactionTemplates()
                ->limit(20)
                ->get()
                ->map(fn($r) => [
                    'description' => $r->description,
                    'amount' => $r->amount_in_cents / 100,
                    'frequency' => $r->frequency,
                    'next_date' => $r->next_date?->format('Y-m-d'),
                ])
                ->toArray(),
        ];
    }

    protected function buildSystemPrompt(array $context): string
    {
        $prompt = "You are a helpful financial assistant for a budget planning application. You help users understand their finances, answer questions about their budget, accounts, and transactions, and provide insights.\n\n";

        if ($context['budget']) {
            $prompt .= "User Information:\n";
            $prompt .= "- Name: {$context['user']['name']}\n";
            $prompt .= "- Current Budget: {$context['budget']['name']}\n";
            $prompt .= "- Total Balance: $" . number_format($context['budget']['total_balance'], 2) . "\n";
            $prompt .= "- Number of Accounts: {$context['budget']['account_count']}\n\n";

            if (!empty($context['accounts'])) {
                $prompt .= "Accounts:\n";
                foreach ($context['accounts'] as $account) {
                    $prompt .= "- {$account['name']} ({$account['type']}): $" . number_format($account['balance'], 2) . "\n";
                }
                $prompt .= "\n";
            }

            if (!empty($context['recent_transactions'])) {
                $prompt .= "Recent Transactions (last 20):\n";
                foreach (array_slice($context['recent_transactions'], 0, 10) as $transaction) {
                    $prompt .= "- {$transaction['date']}: {$transaction['description']} - $" . number_format($transaction['amount'], 2) . " ({$transaction['category']})\n";
                }
                $prompt .= "\n";
            }

            if (!empty($context['recurring_transactions'])) {
                $prompt .= "Active Recurring Transactions:\n";
                foreach ($context['recurring_transactions'] as $recurring) {
                    $prompt .= "- {$recurring['description']}: $" . number_format($recurring['amount'], 2) . " ({$recurring['frequency']})\n";
                }
                $prompt .= "\n";
            }
        } else {
            $prompt .= "The user hasn't set up a budget yet. Help them understand how to get started with the application.\n\n";
        }

        $prompt .= "Guidelines:\n";
        $prompt .= "- Be concise and helpful\n";
        $prompt .= "- Provide specific insights based on their actual data\n";
        $prompt .= "- When analyzing spending, look for patterns and provide actionable advice\n";
        $prompt .= "- If asked about features, explain how to use them\n";
        $prompt .= "- Format currency values with $ and 2 decimal places\n";
        $prompt .= "- Be encouraging and positive about their financial journey\n";

        return $prompt;
    }

    /**
     * Build system prompt using smart context loading.
     * This method formats the dynamically loaded context into a system prompt.
     */
    protected function buildSmartSystemPrompt(array $context, array $requestedTypes): string
    {
        $prompt = "You are a helpful financial assistant for a budget planning application. You help users understand their finances, answer questions about their budget, accounts, and transactions, and provide insights.\n\n";

        // User info
        $firstName = $context['user']['first_name'] ?? 'there';
        $prompt .= "User: {$firstName}\n";

        // Budget info
        if (isset($context['budget']) && $context['budget']) {
            $prompt .= "Budget: {$context['budget']['name']}\n\n";
        } else {
            $prompt .= "\nThe user hasn't set up a budget yet. Help them understand how to get started with the application.\n\n";
            return $prompt . $this->getGuidelines();
        }

        // Format each loaded context type
        foreach ($requestedTypes as $type) {
            if (!isset($context[$type])) {
                continue;
            }

            $prompt .= $this->formatContextType($type, $context[$type]);
        }

        $prompt .= $this->getGuidelines();

        return $prompt;
    }

    /**
     * Format a specific context type for the prompt.
     */
    protected function formatContextType(string $type, array $data): string
    {
        return match ($type) {
            'accounts' => $this->formatAccountsContext($data),
            'account_details' => $this->formatAccountDetailsContext($data),
            'transactions_recent', 'transactions_month' => $this->formatTransactionsContext($data),
            'transactions_by_category' => $this->formatTransactionsByCategoryContext($data),
            'categories' => $this->formatCategoriesContext($data),
            'statistics_monthly' => $this->formatMonthlyStatisticsContext($data),
            'statistics_yearly' => $this->formatYearlyStatisticsContext($data),
            'recurring' => $this->formatRecurringContext($data),
            'projections' => $this->formatProjectionsContext($data),
            'goals' => $this->formatGoalsContext($data),
            'payoff_plans' => $this->formatPayoffPlansContext($data),
            'investments' => $this->formatInvestmentsContext($data),
            'properties' => $this->formatPropertiesContext($data),
            default => '',
        };
    }

    protected function formatAccountsContext(array $data): string
    {
        $prompt = "=== ACCOUNTS ===\n";
        
        if (isset($data['summary'])) {
            $s = $data['summary'];
            $prompt .= "Net Worth: $" . number_format($s['net_worth'] ?? 0, 2) . "\n";
            $prompt .= "Total Assets: $" . number_format($s['total_assets'] ?? 0, 2) . "\n";
            $prompt .= "Total Liabilities: $" . number_format($s['total_liabilities'] ?? 0, 2) . "\n\n";
        }

        foreach ($data['accounts'] ?? [] as $account) {
            $balance = number_format($account['balance'] ?? 0, 2);
            $type = $account['type'] ?? 'unknown';
            $prompt .= "- {$account['name']} ({$type}): $" . $balance . "\n";
        }
        
        return $prompt . "\n";
    }

    protected function formatAccountDetailsContext(array $data): string
    {
        $prompt = "=== CREDIT/LOAN DETAILS ===\n";
        $prompt .= "Total Debt: $" . number_format($data['total_debt'] ?? 0, 2) . "\n\n";

        foreach ($data['liability_accounts'] ?? [] as $account) {
            $prompt .= "{$account['name']}:\n";
            $prompt .= "  Balance: $" . number_format($account['balance'] ?? 0, 2) . "\n";
            if (isset($account['apr'])) {
                $prompt .= "  APR: {$account['apr']}%\n";
            }
            if (isset($account['minimum_payment'])) {
                $prompt .= "  Minimum Payment: $" . number_format($account['minimum_payment'], 2) . "\n";
            }
            if (isset($account['due_date'])) {
                $prompt .= "  Due Date: {$account['due_date']}\n";
            }
            if (isset($account['utilization_percent'])) {
                $prompt .= "  Credit Utilization: {$account['utilization_percent']}%\n";
            }
        }
        
        return $prompt . "\n";
    }

    protected function formatTransactionsContext(array $data): string
    {
        $prompt = "=== TRANSACTIONS ({$data['period']}) ===\n";
        
        if (isset($data['summary'])) {
            $s = $data['summary'];
            $prompt .= "Income: $" . number_format($s['total_income'] ?? 0, 2) . "\n";
            $prompt .= "Expenses: $" . number_format($s['total_expenses'] ?? 0, 2) . "\n";
            $prompt .= "Net: $" . number_format($s['net'] ?? 0, 2) . "\n\n";
        }

        foreach (array_slice($data['transactions'] ?? [], 0, 15) as $t) {
            $amount = number_format($t['amount'] ?? 0, 2);
            $prompt .= "- {$t['date']}: {$t['description']} - $" . $amount . " ({$t['category']})\n";
        }
        
        return $prompt . "\n";
    }

    protected function formatTransactionsByCategoryContext(array $data): string
    {
        $prompt = "=== SPENDING BY CATEGORY ({$data['period']}) ===\n";
        
        foreach ($data['by_category'] ?? [] as $cat) {
            $amount = number_format(abs($cat['total'] ?? 0), 2);
            $type = ($cat['is_expense'] ?? true) ? 'expense' : 'income';
            $prompt .= "- {$cat['category']}: $" . $amount . " ({$cat['transaction_count']} transactions, {$type})\n";
        }
        
        return $prompt . "\n";
    }

    protected function formatCategoriesContext(array $data): string
    {
        $prompt = "=== BUDGET CATEGORIES ({$data['period']}) ===\n";
        
        if (isset($data['summary'])) {
            $s = $data['summary'];
            $prompt .= "Total Allocated: $" . number_format($s['total_allocated'] ?? 0, 2) . "\n";
            $prompt .= "Total Spent: $" . number_format($s['total_spent'] ?? 0, 2) . "\n";
            $prompt .= "Overall Progress: {$s['overall_percent_used']}%\n\n";
        }

        foreach ($data['categories'] ?? [] as $cat) {
            $status = ($cat['is_over_budget'] ?? false) ? ' [OVER BUDGET]' : '';
            $prompt .= "- {$cat['name']}: $" . number_format($cat['spent'] ?? 0, 2) . " / $" . number_format($cat['allocated'] ?? 0, 2) . " ({$cat['percent_used']}%){$status}\n";
        }
        
        return $prompt . "\n";
    }

    protected function formatMonthlyStatisticsContext(array $data): string
    {
        $prompt = "=== MONTHLY STATISTICS ({$data['period']}) ===\n";
        $prompt .= "Total Income: $" . number_format($data['total_income'] ?? 0, 2) . "\n";
        $prompt .= "Total Expenses: $" . number_format($data['total_expenses'] ?? 0, 2) . "\n";
        $prompt .= "Net: $" . number_format($data['net'] ?? 0, 2) . "\n";
        
        if (isset($data['vs_last_month'])) {
            $v = $data['vs_last_month'];
            $prompt .= "\nVs Last Month:\n";
            $prompt .= "  Income Change: " . ($v['income_change_percent'] >= 0 ? '+' : '') . number_format($v['income_change_percent'] ?? 0, 1) . "%\n";
            $prompt .= "  Expenses Change: " . ($v['expenses_change_percent'] >= 0 ? '+' : '') . number_format($v['expenses_change_percent'] ?? 0, 1) . "%\n";
        }

        if (!empty($data['by_category'])) {
            $prompt .= "\nTop Categories:\n";
            foreach (array_slice($data['by_category'], 0, 5) as $cat) {
                $prompt .= "- {$cat['category']}: $" . number_format(abs($cat['amount'] ?? 0), 2) . "\n";
            }
        }
        
        return $prompt . "\n";
    }

    protected function formatYearlyStatisticsContext(array $data): string
    {
        $prompt = "=== YEARLY STATISTICS ({$data['year']}) ===\n";
        
        if (isset($data['yearly_totals'])) {
            $t = $data['yearly_totals'];
            $prompt .= "YTD Income: $" . number_format($t['total_income'] ?? 0, 2) . "\n";
            $prompt .= "YTD Expenses: $" . number_format($t['total_expenses'] ?? 0, 2) . "\n";
            $prompt .= "YTD Net: $" . number_format($t['net'] ?? 0, 2) . "\n";
        }

        if (isset($data['vs_last_year'])) {
            $v = $data['vs_last_year'];
            $prompt .= "\nVs Last Year:\n";
            $prompt .= "  Income Change: " . ($v['income_change_percent'] >= 0 ? '+' : '') . number_format($v['income_change_percent'] ?? 0, 1) . "%\n";
            $prompt .= "  Expenses Change: " . ($v['expenses_change_percent'] >= 0 ? '+' : '') . number_format($v['expenses_change_percent'] ?? 0, 1) . "%\n";
        }
        
        return $prompt . "\n";
    }

    protected function formatRecurringContext(array $data): string
    {
        $prompt = "=== RECURRING TRANSACTIONS ===\n";
        
        if (isset($data['summary'])) {
            $s = $data['summary'];
            $prompt .= "Estimated Monthly Income: $" . number_format($s['estimated_monthly_income'] ?? 0, 2) . "\n";
            $prompt .= "Estimated Monthly Expenses: $" . number_format($s['estimated_monthly_expenses'] ?? 0, 2) . "\n";
            $prompt .= "Estimated Monthly Net: $" . number_format($s['estimated_monthly_net'] ?? 0, 2) . "\n\n";
        }

        foreach ($data['recurring_transactions'] ?? [] as $r) {
            $amount = number_format($r['amount'] ?? 0, 2);
            $prompt .= "- {$r['description']}: $" . $amount . " ({$r['frequency']})\n";
        }
        
        return $prompt . "\n";
    }

    protected function formatProjectionsContext(array $data): string
    {
        $prompt = "=== PROJECTIONS ===\n";
        
        if (isset($data['monthly_cash_flow'])) {
            $cf = $data['monthly_cash_flow'];
            $prompt .= "Projected Monthly Cash Flow: $" . number_format($cf['total_monthly_cash_flow'] ?? 0, 2) . "\n\n";
        }

        if (!empty($data['account_projections'])) {
            $prompt .= "Projected Balances:\n";
            foreach ($data['account_projections'] as $ap) {
                $prompt .= "{$ap['account']} (currently $" . number_format($ap['current_balance'] ?? 0, 2) . "):\n";
                foreach ($ap['projected_balances'] ?? [] as $pb) {
                    $prompt .= "  - {$pb['month']}: $" . number_format($pb['projected_balance'] ?? 0, 2) . "\n";
                }
            }
        }

        if (!empty($data['upcoming_transactions'])) {
            $prompt .= "\nUpcoming Transactions:\n";
            foreach (array_slice($data['upcoming_transactions'], 0, 10) as $ut) {
                $amount = number_format($ut['amount'] ?? 0, 2);
                $prompt .= "- {$ut['date']}: {$ut['description']} - $" . $amount . "\n";
            }
        }
        
        return $prompt . "\n";
    }

    protected function formatGoalsContext(array $data): string
    {
        $prompt = "=== FINANCIAL GOALS ===\n";
        
        foreach ($data['goals'] ?? [] as $goal) {
            $prompt .= "{$goal['name']}:\n";
            $prompt .= "  Target: $" . number_format($goal['target_amount'] ?? 0, 2) . "\n";
            $prompt .= "  Progress: $" . number_format($goal['estimated_progress'] ?? 0, 2) . " ({$goal['percent_complete']}%)\n";
            $prompt .= "  Monthly Contribution: $" . number_format($goal['monthly_contribution'] ?? 0, 2) . "\n";
            if (isset($goal['months_to_goal'])) {
                $prompt .= "  Months to Goal: {$goal['months_to_goal']}\n";
            }
        }
        
        return $prompt . "\n";
    }

    protected function formatPayoffPlansContext(array $data): string
    {
        $prompt = "=== DEBT PAYOFF PLANS ===\n";
        
        foreach ($data['payoff_plans'] ?? [] as $plan) {
            $prompt .= "{$plan['name']} ({$plan['strategy']}):\n";
            $prompt .= "  Total Debt: $" . number_format($plan['total_debt'] ?? 0, 2) . "\n";
            $prompt .= "  Monthly Extra Payment: $" . number_format($plan['monthly_extra_payment'] ?? 0, 2) . "\n";
            
            if (isset($plan['projection'])) {
                $p = $plan['projection'];
                $prompt .= "  Projected Payoff: {$p['projected_payoff_date']} ({$p['months_to_payoff']} months)\n";
                $prompt .= "  Projected Interest: $" . number_format($p['total_interest_paid'] ?? 0, 2) . "\n";
            }
        }
        
        return $prompt . "\n";
    }

    protected function formatInvestmentsContext(array $data): string
    {
        $prompt = "=== INVESTMENTS ===\n";
        $prompt .= "Total Portfolio Value: $" . number_format($data['total_value'] ?? 0, 2) . "\n\n";
        
        foreach ($data['holdings'] ?? [] as $h) {
            $ticker = $h['ticker'] ? " ({$h['ticker']})" : '';
            $prompt .= "- {$h['name']}{$ticker}: $" . number_format($h['value'] ?? 0, 2) . "\n";
        }
        
        return $prompt . "\n";
    }

    protected function formatPropertiesContext(array $data): string
    {
        $prompt = "=== REAL ESTATE ===\n";
        
        if (isset($data['summary'])) {
            $s = $data['summary'];
            $prompt .= "Total Property Value: $" . number_format($s['total_property_value'] ?? 0, 2) . "\n";
            $prompt .= "Total Equity: $" . number_format($s['total_equity'] ?? 0, 2) . "\n\n";
        }

        foreach ($data['properties'] ?? [] as $p) {
            $prompt .= "{$p['name']}:\n";
            $prompt .= "  Value: $" . number_format($p['current_value'] ?? 0, 2) . "\n";
            $prompt .= "  Mortgage Balance: $" . number_format($p['total_mortgage_balance'] ?? 0, 2) . "\n";
            $prompt .= "  Equity: $" . number_format($p['equity'] ?? 0, 2) . " ({$p['equity_percent']}%)\n";
        }
        
        return $prompt . "\n";
    }

    protected function getGuidelines(): string
    {
        return "\n=== GUIDELINES ===\n" .
            "- Be concise and helpful\n" .
            "- Provide specific insights based on the user's actual data shown above\n" .
            "- When analyzing spending, look for patterns and provide actionable advice\n" .
            "- If asked about features, explain how to use them\n" .
            "- Format currency values with $ and 2 decimal places\n" .
            "- Be encouraging and positive about their financial journey\n" .
            "- If you need more specific data to answer a question, let the user know what would help\n";
    }

    protected function generateConversationTitle(ChatConversation $conversation, string $firstMessage): void
    {
        try {
            // Generate a short title from the first message
            $title = substr($firstMessage, 0, 50);
            if (strlen($firstMessage) > 50) {
                $title .= '...';
            }

            $conversation->update(['title' => $title]);
        } catch (\Exception $e) {
            Log::error('Failed to generate conversation title', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getConversations(User $user, int $limit = 10): array
    {
        return ChatConversation::where('user_id', $user->id)
            ->with(['messages' => function ($query) {
                $query->latest()->limit(1);
            }])
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(function ($conversation) {
                return [
                    'id' => $conversation->id,
                    'title' => $conversation->title ?? 'New Conversation',
                    'updated_at' => $conversation->updated_at->diffForHumans(),
                    'message_count' => $conversation->messages()->count(),
                ];
            })
            ->toArray();
    }

    public function deleteConversation(User $user, int $conversationId): bool
    {
        $conversation = ChatConversation::where('user_id', $user->id)
            ->findOrFail($conversationId);

        return $conversation->delete();
    }

    public function bulkDeleteConversations(User $user, array $conversationIds): int
    {
        return ChatConversation::where('user_id', $user->id)
            ->whereIn('id', $conversationIds)
            ->delete();
    }

    public function saveStreamedMessage(User $user, int $conversationId, string $message): array
    {
        try {
            $conversation = ChatConversation::where('user_id', $user->id)
                ->findOrFail($conversationId);

            // Validate AI response
            $responseValidation = $this->moderationService->validateResponse($message);
            if (!$responseValidation['safe']) {
                Log::error('Streamed AI response failed validation', [
                    'user_id' => $user->id,
                    'conversation_id' => $conversationId,
                    'error' => $responseValidation['error'],
                ]);

                return [
                    'success' => false,
                    'error' => 'Response failed validation',
                ];
            }

            // Store assistant response
            $this->storeMessage($conversation, 'assistant', $message);

            // Generate title for conversation if it's the first exchange
            if ($conversation->messages()->count() <= 2 && !$conversation->title) {
                $this->generateConversationTitle($conversation, $conversation->messages()->where('role', 'user')->first()->content);
            }

            Log::info('Streamed response saved', [
                'conversation_id' => $conversationId,
                'response_length' => strlen($message),
            ]);

            return [
                'success' => true,
                'conversation_id' => $conversationId,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to save streamed message', [
                'user_id' => $user->id,
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to save message',
            ];
        }
    }
}
