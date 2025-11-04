<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\FlaggedChatMessage;
use App\Models\User;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Illuminate\Support\Facades\Log;

class ChatService
{
    public function __construct(
        protected ContentModerationService $moderationService
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

            // 8. Build context for LLM
            $context = $this->buildContext($user, $budgetId);

            // 9. Get conversation history
            $conversationHistory = $this->getConversationHistory($conversation);

            // 10. Build system prompt with context and safety guidelines
            $baseSystemPrompt = $this->buildSystemPrompt($context);
            $systemPrompt = $this->moderationService->buildSafeSystemPrompt($baseSystemPrompt);

            // 11. Prepend system message to conversation history
            $messages = array_merge(
                [new SystemMessage($systemPrompt)],
                $conversationHistory
            );

            // 12. Use non-streaming response for now
            // The streaming via asStream() has issues with Laravel's HTTP client
            // consuming the stream before we can iterate
            try {
                Log::info('Generating response for conversation', [
                    'user_id' => $user->id,
                    'conversation_id' => $conversation->id,
                    'message_count' => count($messages),
                ]);

                // Generate the complete response (non-streaming)
                $response = Prism::text()
                    ->using('openai', 'gpt-4o-mini')
                    ->withMessages($messages)
                    ->generate();

                $assistantMessage = $response->text;

                // Validate AI response
                $responseValidation = $this->moderationService->validateResponse($assistantMessage);
                if (!$responseValidation['safe']) {
                    Log::error('AI response failed validation', [
                        'user_id' => $user->id,
                        'conversation_id' => $conversation->id,
                        'error' => $responseValidation['error'],
                    ]);

                    return response()->json([
                        'error' => 'Unable to generate a safe response. Please try rephrasing your question.',
                        'success' => false,
                    ], 400);
                }

                // Store assistant response
                $this->storeMessage($conversation, 'assistant', $assistantMessage);

                // Generate title for conversation if it's the first exchange
                if ($conversation->messages()->count() <= 2 && !$conversation->title) {
                    $this->generateConversationTitle($conversation, $message);
                }

                Log::info('Response generated and saved', [
                    'conversation_id' => $conversation->id,
                    'response_length' => strlen($assistantMessage),
                ]);

                // Return as JSON with conversation_id and message
                return response()->json([
                    'success' => true,
                    'conversation_id' => $conversation->id,
                    'message' => $assistantMessage,
                ]);
            } catch (\Exception $e) {
                Log::error('Prism generation error', [
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return response()->json([
                    'error' => 'Failed to generate response. Please try again.',
                    'success' => false,
                ], 500);
            }
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

            // 8. Build context for LLM
            $context = $this->buildContext($user, $budgetId);

            // 9. Get conversation history
            $conversationHistory = $this->getConversationHistory($conversation);

            // 10. Build system prompt with context and safety guidelines
            $baseSystemPrompt = $this->buildSystemPrompt($context);
            $systemPrompt = $this->moderationService->buildSafeSystemPrompt($baseSystemPrompt);

            // 11. Prepend system message to conversation history
            $messages = array_merge(
                [new SystemMessage($systemPrompt)],
                $conversationHistory
            );

            // 12. Call LLM via Prism
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

        // Calculate totals
        $totalBalance = $activeBudget->accounts->sum(function ($account) {
            return $account->current_balance_cents / 100;
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
}
