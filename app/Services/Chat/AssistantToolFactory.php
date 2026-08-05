<?php

namespace App\Services\Chat;

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
use App\Services\Chat\ContextBuilders\TransactionQueryContextBuilder;
use Closure;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Tool;

/**
 * Builds the set of Prism tools the AI assistant can call to fetch a user's real
 * financial data on demand.
 *
 * Each tool is scoped to a single, ownership-resolved Budget captured in its closure,
 * so the model can never reach another user's or budget's data — no tool accepts a
 * budget or user identifier. Every result is scrubbed of PII and returned as a JSON
 * string (Prism requires tool callbacks to return strings).
 */
class AssistantToolFactory
{
    /**
     * Maximum number of tool invocations allowed within a single chat turn.
     * Bounds cost even if the agent loop would otherwise keep calling tools.
     */
    protected const MAX_TOOL_CALLS = 12;

    /**
     * Soft cap on the size of a single tool result before it is truncated.
     */
    protected const MAX_JSON_BYTES = 8000;

    public function __construct(
        protected PIIScrubberService $piiScrubber
    ) {}

    /**
     * Build the tools available for the given user + budget.
     *
     * The budget must already be ownership-resolved by the caller. Returns an empty
     * array when there is no budget, in which case the assistant has no data tools.
     *
     * @return array<int, Tool>
     */
    public function make(User $user, ?Budget $budget): array
    {
        if (! $budget) {
            return [];
        }

        // Shared per-turn tool-call counter, captured by reference by every tool guard.
        $callCount = 0;

        return [
            $this->tool(
                'get_accounts',
                'List all of the budget\'s accounts with their type and current balance, plus a net-worth / total-assets / total-liabilities summary. Balances are in dollars.',
                fn (array $args) => app(AccountContextBuilder::class)->build($user, $budget),
                $callCount,
            ),
            $this->tool(
                'get_account_details',
                'Get credit card and loan details for liability accounts: balance owed, APR, minimum payment, due date, and credit utilization.',
                fn (array $args) => app(AccountDetailsContextBuilder::class)->build($user, $budget),
                $callCount,
            ),
            $this->tool(
                'query_transactions',
                'Search the budget\'s transactions. Optional filters: start_date and end_date (YYYY-MM-DD, restricted to the last 12 months), category (name), account_id, and search (matches the description). limit defaults to 25 (max 100). Amounts are in dollars: positive = income, negative = expense.',
                fn (array $args) => app(TransactionQueryContextBuilder::class)->build($user, $budget, [
                    'start_date' => $args['start_date'] ?? null,
                    'end_date' => $args['end_date'] ?? null,
                    'category' => $args['category'] ?? null,
                    'account_id' => $args['account_id'] ?? null,
                    'search' => $args['search'] ?? null,
                    'limit' => $args['limit'] ?? null,
                ]),
                $callCount,
                function (Tool $tool) {
                    $tool->withStringParameter('start_date', 'Earliest transaction date (YYYY-MM-DD). Defaults to 12 months ago.', false)
                        ->withStringParameter('end_date', 'Latest transaction date (YYYY-MM-DD). Defaults to today.', false)
                        ->withStringParameter('category', 'Filter to transactions whose category contains this text.', false)
                        ->withNumberParameter('account_id', 'Restrict to a single account by its numeric id (from get_accounts).', false)
                        ->withStringParameter('search', 'Filter to transactions whose description contains this text.', false)
                        ->withNumberParameter('limit', 'Maximum number of transactions to return (default 25, max 100).', false);
                },
            ),
            $this->tool(
                'get_spending_by_category',
                'Get spending and income grouped by category for a single month. Optional month (YYYY-MM) defaults to the current month.',
                fn (array $args) => app(TransactionContextBuilder::class)->build($user, $budget, [
                    'type' => 'by_category',
                    'month' => $args['month'] ?? null,
                ]),
                $callCount,
                function (Tool $tool) {
                    $tool->withStringParameter('month', 'Month to summarize as YYYY-MM. Defaults to the current month.', false);
                },
            ),
            $this->tool(
                'get_budget_categories',
                'Get the budget\'s categories with their allocated amount, amount spent, percent used, and whether each is over budget.',
                fn (array $args) => app(CategoryContextBuilder::class)->build($user, $budget),
                $callCount,
            ),
            $this->tool(
                'get_monthly_statistics',
                'Get this month\'s income vs. expenses with a top-category breakdown and comparison to last month.',
                fn (array $args) => app(StatisticsContextBuilder::class)->build($user, $budget, ['type' => 'monthly']),
                $callCount,
            ),
            $this->tool(
                'get_yearly_statistics',
                'Get year-to-date income vs. expenses, a month-by-month breakdown, and comparison to last year.',
                fn (array $args) => app(StatisticsContextBuilder::class)->build($user, $budget, ['type' => 'yearly']),
                $callCount,
            ),
            $this->tool(
                'get_recurring_transactions',
                'List recurring bills and scheduled transactions with their amounts and frequency, plus estimated monthly income/expenses/net.',
                fn (array $args) => app(RecurringContextBuilder::class)->build($user, $budget),
                $callCount,
            ),
            $this->tool(
                'get_projections',
                'Get projected future account balances, upcoming transactions, and monthly cash-flow forecast. Optional months (default 3, max 12).',
                fn (array $args) => app(ProjectionsContextBuilder::class)->build($user, $budget, [
                    'months' => $this->clampMonths($args['months'] ?? null),
                ]),
                $callCount,
                function (Tool $tool) {
                    $tool->withNumberParameter('months', 'How many months to project forward (default 3, max 12).', false);
                },
            ),
            $this->tool(
                'get_goals',
                'List the budget\'s financial goals with target amount, progress, monthly contribution, and estimated months to completion.',
                fn (array $args) => app(GoalsContextBuilder::class)->build($user, $budget),
                $callCount,
            ),
            $this->tool(
                'get_payoff_plans',
                'Get debt payoff plans: strategy, total debt, extra monthly payment, projected payoff date, and projected interest.',
                fn (array $args) => app(PayoffContextBuilder::class)->build($user, $budget),
                $callCount,
            ),
            $this->tool(
                'get_investments',
                'Get investment portfolio holdings and their values, plus total portfolio value.',
                fn (array $args) => app(InvestmentContextBuilder::class)->build($user, $budget),
                $callCount,
            ),
            $this->tool(
                'get_properties',
                'Get real-estate holdings with current value, linked mortgage balance, and equity.',
                fn (array $args) => app(PropertyContextBuilder::class)->build($user, $budget),
                $callCount,
            ),
        ];
    }

    /**
     * Assemble a single scoped tool: configure params, then wrap the resolver with the
     * per-turn call budget, error handling, PII scrubbing, and JSON encoding.
     *
     * @param  callable(array<string,mixed>):array<string,mixed>  $resolve
     * @param  null|callable(Tool):void  $configure
     */
    protected function tool(string $name, string $description, callable $resolve, int &$callCount, ?callable $configure = null): Tool
    {
        $tool = (new Tool)
            ->as($name)
            ->for($description)
            ->using($this->guard($name, $resolve, $callCount));

        if ($configure) {
            $configure($tool);
        }

        return $tool;
    }

    /**
     * Wrap a data resolver so every invocation is counted, guarded, scrubbed, and
     * serialized to the JSON string Prism requires.
     *
     * @param  callable(array<string,mixed>):array<string,mixed>  $resolve
     */
    protected function guard(string $name, callable $resolve, int &$callCount): Closure
    {
        return function (...$args) use ($name, $resolve, &$callCount): string {
            $callCount++;

            if ($callCount > self::MAX_TOOL_CALLS) {
                return json_encode([
                    'error' => 'Tool-call budget exhausted for this turn. Answer using the data already gathered.',
                ]);
            }

            // Prism spreads the tool call's named arguments, which PHP's variadic
            // collects back into a string-keyed associative array.
            $named = $args;

            try {
                $data = $resolve($named);
            } catch (\Throwable $e) {
                Log::error("Assistant tool {$name} failed", ['error' => $e->getMessage()]);

                return json_encode(['error' => 'Unable to load this data right now.']);
            }

            Log::info('Assistant tool invoked', ['tool' => $name, 'args' => $named]);

            $scrubbed = $this->piiScrubber->scrub($data);

            return $this->encode($scrubbed);
        };
    }

    /**
     * Encode a scrubbed result to JSON, truncating oversized list data as a safety net.
     *
     * @param  array<string,mixed>  $data
     */
    protected function encode(array $data): string
    {
        $json = json_encode($data);

        if ($json !== false && strlen($json) <= self::MAX_JSON_BYTES) {
            return $json;
        }

        $trimmed = $this->truncateLists($data, 20);
        $trimmed['_note'] = 'Result truncated to fit the context window. Narrow your query for more detail.';

        return json_encode($trimmed) ?: '{}';
    }

    /**
     * Recursively cap any list-style arrays to at most $max entries.
     */
    protected function truncateLists(mixed $value, int $max): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $value = array_slice($value, 0, $max);
        }

        return array_map(fn ($item) => $this->truncateLists($item, $max), $value);
    }

    /**
     * Clamp a requested projection window to a sane 1–12 month range.
     */
    protected function clampMonths(mixed $months): int
    {
        $months = (int) ($months ?? 3);

        return max(1, min($months, 12));
    }
}
