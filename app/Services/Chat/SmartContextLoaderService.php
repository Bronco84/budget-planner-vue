<?php

namespace App\Services\Chat;

use App\Contracts\ContextBuilderInterface;
use App\Models\Budget;
use App\Models\User;
use App\Services\Chat\ContextBuilders\AccountContextBuilder;
use App\Services\Chat\ContextBuilders\AccountDetailsContextBuilder;
use App\Services\Chat\ContextBuilders\CategoryContextBuilder;
use App\Services\Chat\ContextBuilders\GoalsContextBuilder;
use App\Services\Chat\ContextBuilders\InvestmentContextBuilder;
use App\Services\Chat\ContextBuilders\PayoffContextBuilder;
use App\Services\Chat\ContextBuilders\ProjectionsContextBuilder;
use App\Services\Chat\ContextBuilders\PropertyContextBuilder;
use App\Services\Chat\ContextBuilders\RecurringContextBuilder;
use App\Services\Chat\ContextBuilders\StatisticsContextBuilder;
use App\Services\Chat\ContextBuilders\TransactionContextBuilder;
use Illuminate\Support\Facades\Log;

class SmartContextLoaderService
{
    /**
     * Maximum tokens to allocate for context.
     */
    protected const MAX_CONTEXT_TOKENS = 3000;

    /**
     * Registered context builders.
     */
    protected array $builders = [];

    public function __construct(
        protected PIIScrubberService $piiScrubber
    ) {
        $this->registerBuilders();
    }

    /**
     * Register all available context builders.
     */
    protected function registerBuilders(): void
    {
        $this->builders = [
            'accounts' => app(AccountContextBuilder::class),
            'account_details' => app(AccountDetailsContextBuilder::class),
            'transactions_recent' => app(TransactionContextBuilder::class),
            'transactions_month' => app(TransactionContextBuilder::class),
            'transactions_by_category' => app(TransactionContextBuilder::class),
            'categories' => app(CategoryContextBuilder::class),
            'statistics_monthly' => app(StatisticsContextBuilder::class),
            'statistics_yearly' => app(StatisticsContextBuilder::class),
            'recurring' => app(RecurringContextBuilder::class),
            'projections' => app(ProjectionsContextBuilder::class),
            'goals' => app(GoalsContextBuilder::class),
            'payoff_plans' => app(PayoffContextBuilder::class),
            'investments' => app(InvestmentContextBuilder::class),
            'properties' => app(PropertyContextBuilder::class),
        ];
    }

    /**
     * Load context based on requested types.
     *
     * @param User $user The authenticated user
     * @param int|null $budgetId The budget ID (or null to use active budget)
     * @param array $requestedTypes Array of context type identifiers
     * @return array The loaded and scrubbed context
     */
    public function load(User $user, ?int $budgetId, array $requestedTypes): array
    {
        $budget = $this->getBudget($user, $budgetId);

        if (!$budget) {
            return $this->buildMinimalContext($user);
        }

        // Start with base context
        $context = $this->buildBaseContext($user, $budget);

        // Track token usage
        $estimatedTokens = 100; // Base context tokens

        // Load requested context types
        foreach ($requestedTypes as $type) {
            if (!isset($this->builders[$type])) {
                Log::warning("Unknown context type requested: {$type}");
                continue;
            }

            $builder = $this->builders[$type];

            // Check token budget
            $typeTokens = $builder->getTokenEstimate($budget);
            if ($estimatedTokens + $typeTokens > self::MAX_CONTEXT_TOKENS) {
                Log::info("Skipping context type due to token budget", [
                    'type' => $type,
                    'estimated_tokens' => $typeTokens,
                    'current_total' => $estimatedTokens,
                ]);
                continue;
            }

            // Build context with appropriate options
            $options = $this->getOptionsForType($type);
            
            try {
                $typeContext = $builder->build($user, $budget, $options);
                $context[$type] = $typeContext;
                $estimatedTokens += $typeTokens;
            } catch (\Exception $e) {
                Log::error("Failed to build context for type: {$type}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Scrub PII from the entire context
        $scrubbedContext = $this->piiScrubber->scrub($context);

        Log::info('Context loaded successfully', [
            'requested_types' => $requestedTypes,
            'loaded_types' => array_keys(array_filter($context, fn($v, $k) => !in_array($k, ['user', 'budget']), ARRAY_FILTER_USE_BOTH)),
            'estimated_tokens' => $estimatedTokens,
        ]);

        return $scrubbedContext;
    }

    /**
     * Get the budget for context loading.
     */
    protected function getBudget(User $user, ?int $budgetId): ?Budget
    {
        if ($budgetId) {
            return Budget::where('user_id', $user->id)->find($budgetId);
        }

        return $user->getActiveBudget();
    }

    /**
     * Build base context that's always included.
     */
    protected function buildBaseContext(User $user, Budget $budget): array
    {
        return [
            'user' => [
                'first_name' => $this->piiScrubber->extractFirstName($user->name),
            ],
            'budget' => [
                'name' => $budget->name,
                'account_count' => $budget->accounts->count(),
            ],
        ];
    }

    /**
     * Build minimal context when no budget is available.
     */
    protected function buildMinimalContext(User $user): array
    {
        return [
            'user' => [
                'first_name' => $this->piiScrubber->extractFirstName($user->name),
            ],
            'budget' => null,
            'message' => 'No budget has been set up yet.',
        ];
    }

    /**
     * Get options for a specific context type.
     */
    protected function getOptionsForType(string $type): array
    {
        return match ($type) {
            'transactions_recent' => ['type' => 'recent'],
            'transactions_month' => ['type' => 'month'],
            'transactions_by_category' => ['type' => 'by_category'],
            'statistics_monthly' => ['type' => 'monthly'],
            'statistics_yearly' => ['type' => 'yearly'],
            'projections' => ['months' => 3],
            default => [],
        };
    }

    /**
     * Get all available context types.
     */
    public function getAvailableTypes(): array
    {
        return array_keys($this->builders);
    }

    /**
     * Estimate total tokens for a set of context types.
     */
    public function estimateTokens(Budget $budget, array $types): int
    {
        $total = 100; // Base context

        foreach ($types as $type) {
            if (isset($this->builders[$type])) {
                $total += $this->builders[$type]->getTokenEstimate($budget);
            }
        }

        return $total;
    }
}
