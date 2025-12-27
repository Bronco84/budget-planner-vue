<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Transaction;
use App\Models\RecurringTransactionTemplate;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class RecurringTransactionAnalysisService
{
    protected RecurringTransactionService $recurringTransactionService;

    public function __construct(RecurringTransactionService $recurringTransactionService)
    {
        $this->recurringTransactionService = $recurringTransactionService;
    }

    /**
     * Analyze transactions for recurring patterns.
     */
    public function analyzeAccount(
        Account $account,
        int $analysisPeriodMonths = 6,
        int $minOccurrences = 3,
        float $confidenceThreshold = 0.6
    ): array {
        $startDate = Carbon::now()->subMonths($analysisPeriodMonths);
        $endDate = Carbon::now();

        Log::info('Starting recurring transaction analysis', [
            'budget_id' => $account->budget_id,
            'account_id' => $account->id,
            'analysis_period_months' => $analysisPeriodMonths,
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
        ]);

        // Get transactions for the analysis period with Plaid data for original descriptions
        $transactions = Transaction::where('budget_id', $account->budget_id)
            ->where('account_id', $account->id)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNull('recurring_transaction_template_id') // Only unlinked transactions
            ->with('plaidTransaction')
            ->orderBy('date', 'desc')
            ->get();

        if ($transactions->isEmpty()) {
            return [
                'success' => false,
                'message' => 'No unlinked transactions found for the selected account and time period.',
                'patterns' => [],
                'analysis_summary' => [
                    'total_transactions' => 0,
                    'patterns_found' => 0,
                    'pattern_groups' => 0,
                    'average_confidence' => null,
                    'analysis_period' => [
                        'start_date' => $startDate->toDateString(),
                        'end_date' => $endDate->toDateString(),
                        'months' => $analysisPeriodMonths,
                    ],
                    'criteria' => [
                        'min_occurrences' => $minOccurrences,
                        'confidence_threshold' => $confidenceThreshold,
                    ]
                ]
            ];
        }

        // Perform pattern analysis
        $patterns = $this->analyzeTransactionPatterns(
            $transactions,
            $minOccurrences,
            $confidenceThreshold
        );

        // Enhance patterns with Plaid data if available
        $patterns = $this->enhanceWithPlaidData($account, $patterns);

        Log::info('Recurring transaction analysis completed', [
            'patterns_found' => count($patterns),
            'account_id' => $account->id,
        ]);

        // Calculate average confidence score
        $averageConfidence = null;
        if (count($patterns) > 0) {
            $totalConfidence = array_sum(array_column($patterns, 'confidence_score'));
            $averageConfidence = $totalConfidence / count($patterns);
        }

        return [
            'success' => true,
            'patterns' => $patterns,
            'analysis_summary' => [
                'total_transactions' => $transactions->count(),
                'patterns_found' => count($patterns),
                'pattern_groups' => count($patterns), // Same as patterns_found for now
                'average_confidence' => $averageConfidence,
                'analysis_period' => [
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                    'months' => $analysisPeriodMonths,
                ],
                'criteria' => [
                    'min_occurrences' => $minOccurrences,
                    'confidence_threshold' => $confidenceThreshold,
                ]
            ]
        ];
    }

    /**
     * Create recurring transaction templates from analysis results.
     */
    public function createTemplatesFromPatterns(Budget $budget, Account $account, array $patterns): array
    {
        $createdTemplates = [];
        $errors = [];

        foreach ($patterns as $index => $patternData) {
            try {
                // Prepare template data
                $templateData = $this->prepareTemplateData($budget, $account, $patternData);

                // Create the recurring transaction template
                $template = RecurringTransactionTemplate::create($templateData);

                // Link existing transactions that match this pattern
                $linkedCount = $this->linkMatchingTransactionsToTemplate($template, $patternData);

                $createdTemplates[] = [
                    'template' => $template,
                    'linked_transactions' => $linkedCount,
                    'pattern_data' => $patternData
                ];

                Log::info('Created recurring transaction template from analysis', [
                    'template_id' => $template->id,
                    'description' => $template->description,
                    'linked_transactions' => $linkedCount,
                ]);

            } catch (\Exception $e) {
                $errors[] = [
                    'index' => $index,
                    'description' => $patternData['description'] ?? 'Unknown pattern',
                    'error' => $e->getMessage()
                ];

                Log::error('Failed to create recurring transaction template', [
                    'error' => $e->getMessage(),
                    'pattern_data' => $patternData,
                    'budget_id' => $budget->id,
                ]);
            }
        }

        return [
            'success' => count($createdTemplates) > 0,
            'created_templates' => count($createdTemplates),
            'total_requested' => count($patterns),
            'templates' => $createdTemplates,
            'errors' => $errors,
        ];
    }

    /**
     * Analyze transaction patterns to identify recurring transactions.
     */
    protected function analyzeTransactionPatterns($transactions, $minOccurrences, $confidenceThreshold): array
    {
        $patterns = [];
        $groupedTransactions = [];

        // Group transactions by similar description patterns
        foreach ($transactions as $transaction) {
            $normalizedDescription = $this->normalizeDescription($transaction->description);
            $key = $this->generateDescriptionKey($normalizedDescription);

            // Get original description from Plaid if available, otherwise use transaction description
            $originalDescription = $this->getOriginalDescription($transaction);

            if (!isset($groupedTransactions[$key])) {
                $groupedTransactions[$key] = [
                    'transactions' => collect(),
                    'normalized_description' => $normalizedDescription,
                    'original_description' => $originalDescription,
                ];
            }

            $groupedTransactions[$key]['transactions']->push($transaction);
        }

        // Analyze each group for recurring patterns
        foreach ($groupedTransactions as $key => $group) {
            $groupTransactions = $group['transactions'];

            // Skip if not enough occurrences
            if ($groupTransactions->count() < $minOccurrences) {
                continue;
            }

            // Analyze the pattern
            $pattern = $this->analyzeTransactionGroup($groupTransactions, $group);

            if ($pattern && $pattern['confidence_score'] >= $confidenceThreshold) {
                $patterns[] = $pattern;
            }
        }

        // Sort patterns by confidence score (highest first)
        usort($patterns, function ($a, $b) {
            return $b['confidence_score'] <=> $a['confidence_score'];
        });

        return $patterns;
    }

    /**
     * Analyze a group of similar transactions to determine recurring pattern.
     */
    protected function analyzeTransactionGroup($transactions, $group): ?array
    {
        $sortedTransactions = $transactions->sortBy('date');
        $amounts = $transactions->pluck('amount_in_cents');

        // Calculate basic statistics
        $avgAmount = $amounts->avg();
        $minAmount = $amounts->min();
        $maxAmount = $amounts->max();
        $medianAmount = $amounts->median();
        $stdDevAmount = $this->calculateStandardDeviation($amounts->toArray());

        // Detect frequency pattern
        $frequencyAnalysis = $this->detectFrequencyPattern($sortedTransactions);

        if (!$frequencyAnalysis) {
            return null;
        }

        // Calculate confidence score
        $confidenceFactors = $this->calculateConfidenceFactors($transactions, $frequencyAnalysis);
        $confidenceScore = $this->calculateOverallConfidence($confidenceFactors);

        // Determine if amount is dynamic based on variance
        $coefficientOfVariation = abs($avgAmount) > 0 ? ($stdDevAmount / abs($avgAmount)) : 0;
        $isDynamicAmount = $coefficientOfVariation > 0.15; // 15% variation threshold

        // Get the most recent transaction for category
        $latestTransaction = $sortedTransactions->last();

        // Calculate suggested start date (first occurrence date)
        $firstOccurrence = $sortedTransactions->first();
        $suggestedStartDate = $firstOccurrence->date;

        // Extract Plaid entity information from the most common counterparty
        $entityInfo = $this->extractPrimaryEntityFromTransactions($transactions);

        return [
            'description' => $group['normalized_description'],
            'original_description' => $group['original_description'],
            'category' => $latestTransaction->category,
            'frequency' => $frequencyAnalysis['frequency'],
            'day_of_month' => $frequencyAnalysis['day_of_month'],
            'day_of_week' => $frequencyAnalysis['day_of_week'],
            'first_day_of_month' => $frequencyAnalysis['first_day_of_month'],
            'amount_in_cents' => (int) $avgAmount,
            'is_dynamic_amount' => $isDynamicAmount,
            'suggested_start_date' => $suggestedStartDate->toDateString(),
            'plaid_entity_id' => $entityInfo['entity_id'],
            'plaid_entity_name' => $entityInfo['entity_name'],
            'amount_stats' => [
                'average' => $avgAmount,
                'median' => $medianAmount,
                'min' => $minAmount,
                'max' => $maxAmount,
                'std_dev' => $stdDevAmount,
                'coefficient_of_variation' => $coefficientOfVariation,
            ],
            'occurrences' => $transactions->count(),
            'occurrence_count' => $transactions->count(), // Add for Vue component compatibility
            'date_range' => [
                'first' => $sortedTransactions->first()->date->toDateString(),
                'last' => $sortedTransactions->last()->date->toDateString(),
            ],
            'last_transaction_date' => $sortedTransactions->last()->date->toDateString(), // Add for Vue component compatibility
            'confidence_score' => $confidenceScore,
            'confidence' => $confidenceScore, // Add for Vue component compatibility
            'average_amount' => $avgAmount / 100, // Add for Vue component compatibility (convert cents to dollars)
            'min_amount' => $minAmount / 100, // Add for Vue component compatibility (convert cents to dollars)
            'max_amount' => $maxAmount / 100, // Add for Vue component compatibility (convert cents to dollars)
            'standard_deviation' => $stdDevAmount, // Add for Vue component compatibility
            'confidence_factors' => $confidenceFactors,
            'frequency_analysis' => $frequencyAnalysis,
            'transactions' => $transactions->map(function ($t) {
                return [
                    'id' => $t->id,
                    'date' => $t->date->toDateString(),
                    'description' => $t->description,
                    'amount_in_cents' => $t->amount_in_cents,
                    'category' => $t->category,
                ];
            })->values(),
        ];
    }

    /**
     * Detect the frequency pattern of transactions.
     */
    protected function detectFrequencyPattern($sortedTransactions): ?array
    {
        if ($sortedTransactions->count() < 2) {
            return null;
        }

        $dates = $sortedTransactions->pluck('date')->values();
        $intervals = [];

        // Calculate intervals between consecutive transactions
        for ($i = 1; $i < $dates->count(); $i++) {
            $intervals[] = $dates[$i-1]->diffInDays($dates[$i]);
        }

        if (empty($intervals)) {
            return null;
        }

        // Analyze interval patterns
        $avgInterval = array_sum($intervals) / count($intervals);
        $intervalVariance = $this->calculateVariance($intervals);

        // Determine frequency based on average interval
        $frequency = null;
        $dayOfMonth = null;
        $dayOfWeek = null;
        $firstDayOfMonth = null;

        if ($avgInterval >= 350 && $avgInterval <= 380) {
            // Yearly pattern
            $frequency = 'yearly';
            $firstDate = $dates->first();
            $dayOfMonth = $firstDate->day;
        } elseif ($avgInterval >= 85 && $avgInterval <= 95) {
            // Quarterly pattern
            $frequency = 'quarterly';
            $dayOfMonth = $this->findMostCommonDayOfMonth($dates);
        } elseif ($avgInterval >= 28 && $avgInterval <= 32) {
            // Monthly pattern
            $frequency = 'monthly';
            $dayOfMonth = $this->findMostCommonDayOfMonth($dates);
        } elseif ($avgInterval >= 13 && $avgInterval <= 16) {
            // Biweekly pattern
            $frequency = 'biweekly';
            $dayOfWeek = $this->findMostCommonDayOfWeek($dates);
        } elseif ($avgInterval >= 13 && $avgInterval <= 18) {
            // Bimonthly pattern (twice per month)
            if ($this->isBimonthlyPattern($dates)) {
                $frequency = 'bimonthly';
                $bimonthlyDays = $this->findBimonthlyDays($dates);
                $dayOfMonth = $bimonthlyDays['second'];
                $firstDayOfMonth = $bimonthlyDays['first'];
            }
        } elseif ($avgInterval >= 6 && $avgInterval <= 8) {
            // Weekly pattern
            $frequency = 'weekly';
            $dayOfWeek = $this->findMostCommonDayOfWeek($dates);
        } elseif ($avgInterval >= 0.8 && $avgInterval <= 1.2) {
            // Daily pattern
            $frequency = 'daily';
        }

        // Validate the detected pattern has reasonable consistency
        if (!$frequency || $intervalVariance > pow($avgInterval * 0.5, 2)) {
            return null;
        }

        return [
            'frequency' => $frequency,
            'day_of_month' => $dayOfMonth,
            'day_of_week' => $dayOfWeek,
            'first_day_of_month' => $firstDayOfMonth,
            'average_interval' => $avgInterval,
            'interval_variance' => $intervalVariance,
        ];
    }

    /**
     * Calculate confidence factors for a recurring pattern.
     */
    protected function calculateConfidenceFactors($transactions, $frequencyAnalysis): array
    {
        $factors = [];

        // Factor 1: Number of occurrences
        $occurrences = $transactions->count();
        if ($occurrences >= 6) {
            $factors['occurrences'] = ['score' => 1.0, 'description' => "Strong pattern with {$occurrences} occurrences"];
        } elseif ($occurrences >= 4) {
            $factors['occurrences'] = ['score' => 0.8, 'description' => "Good pattern with {$occurrences} occurrences"];
        } else {
            $factors['occurrences'] = ['score' => 0.5, 'description' => "Weak pattern with only {$occurrences} occurrences"];
        }

        // Factor 2: Interval consistency
        $intervalVariance = $frequencyAnalysis['interval_variance'];
        $avgInterval = $frequencyAnalysis['average_interval'];
        $intervalConsistency = $avgInterval > 0 ? (1 - min(1, sqrt($intervalVariance) / $avgInterval)) : 0;

        if ($intervalConsistency >= 0.8) {
            $factors['timing_consistency'] = ['score' => 1.0, 'description' => 'Very consistent timing pattern'];
        } elseif ($intervalConsistency >= 0.6) {
            $factors['timing_consistency'] = ['score' => 0.7, 'description' => 'Moderately consistent timing'];
        } else {
            $factors['timing_consistency'] = ['score' => 0.3, 'description' => 'Inconsistent timing pattern'];
        }

        // Factor 3: Amount consistency
        $amounts = $transactions->pluck('amount_in_cents');
        $avgAmount = $amounts->avg();
        $stdDev = $this->calculateStandardDeviation($amounts->toArray());
        $coefficientOfVariation = abs($avgAmount) > 0 ? ($stdDev / abs($avgAmount)) : 1;

        if ($coefficientOfVariation <= 0.1) {
            $factors['amount_consistency'] = ['score' => 1.0, 'description' => 'Very consistent amounts'];
        } elseif ($coefficientOfVariation <= 0.3) {
            $factors['amount_consistency'] = ['score' => 0.8, 'description' => 'Reasonably consistent amounts'];
        } elseif ($coefficientOfVariation <= 0.5) {
            $factors['amount_consistency'] = ['score' => 0.5, 'description' => 'Moderate amount variation'];
        } else {
            $factors['amount_consistency'] = ['score' => 0.2, 'description' => 'High amount variation'];
        }

        // Factor 4: Recency
        $mostRecentDate = $transactions->max('date');
        $daysSinceLastOccurrence = Carbon::now()->diffInDays($mostRecentDate);

        if ($daysSinceLastOccurrence <= 7) {
            $factors['recency'] = ['score' => 1.0, 'description' => 'Very recent activity'];
        } elseif ($daysSinceLastOccurrence <= 30) {
            $factors['recency'] = ['score' => 0.9, 'description' => 'Recent activity'];
        } elseif ($daysSinceLastOccurrence <= 60) {
            $factors['recency'] = ['score' => 0.7, 'description' => 'Moderately recent activity'];
        } else {
            $factors['recency'] = ['score' => 0.4, 'description' => "Last activity {$daysSinceLastOccurrence} days ago"];
        }

        return $factors;
    }

    /**
     * Calculate overall confidence score from factors.
     */
    protected function calculateOverallConfidence($factors): float
    {
        if (empty($factors)) {
            return 0.0;
        }

        $weights = [
            'occurrences' => 0.3,
            'timing_consistency' => 0.3,
            'amount_consistency' => 0.2,
            'recency' => 0.2,
        ];

        $weightedSum = 0;
        $totalWeight = 0;

        foreach ($factors as $factorName => $factorData) {
            if (isset($weights[$factorName])) {
                $weightedSum += $factorData['score'] * $weights[$factorName];
                $totalWeight += $weights[$factorName];
            }
        }

        return $totalWeight > 0 ? $weightedSum / $totalWeight : 0.0;
    }

    /**
     * Prepare template data from pattern analysis.
     */
    protected function prepareTemplateData(Budget $budget, Account $account, array $patternData): array
    {
        // Check if this is data from the confirmation modal (has user-edited form fields)
        $isConfirmationData = isset($patternData['description']) && isset($patternData['frequency']) &&
                              !isset($patternData['suggested_start_date']) && !isset($patternData['confidence_score']);

        if ($isConfirmationData) {
            // Handle user-edited confirmation data
            // Always store the amount - for dynamic amounts, this is the estimated/average amount
            $amountInCents = intval(($patternData['amount'] ?? 0) * 100);
            
            // Determine if this is an expense (negative) based on the main amount
            $isExpense = $amountInCents < 0;
            
            // Ensure min/max have the same sign as the main amount
            // Frontend sends positive values, we apply the sign here
            $minAmount = null;
            if ($patternData['amount_type'] === 'dynamic' && $patternData['min_amount'] !== null) {
                $minCents = intval(abs($patternData['min_amount']) * 100);
                $minAmount = $isExpense ? -$minCents : $minCents;
            }
            
            $maxAmount = null;
            if ($patternData['amount_type'] === 'dynamic' && $patternData['max_amount'] !== null) {
                $maxCents = intval(abs($patternData['max_amount']) * 100);
                $maxAmount = $isExpense ? -$maxCents : $maxCents;
            }
            
            return [
                'budget_id' => $budget->id,
                'account_id' => $account->id,
                'description' => $patternData['description'],
                'category' => $patternData['category'] ?? null,
                'amount_in_cents' => $amountInCents,
                'frequency' => $patternData['frequency'],
                'start_date' => $patternData['start_date'],
                'end_date' => !empty($patternData['end_date']) ? $patternData['end_date'] : null,
                'day_of_month' => $patternData['day_of_month'] ?? null,
                'day_of_week' => $patternData['day_of_week'] ?? null,
                'bimonthly_first_day' => $patternData['bimonthly_first_day'] ?? null,
                'bimonthly_second_day' => $patternData['bimonthly_second_day'] ?? null,
                'is_dynamic_amount' => $patternData['amount_type'] === 'dynamic',
                'average_amount' => $patternData['amount'] ?? null,
                'min_amount' => $minAmount,
                'max_amount' => $maxAmount,
                'plaid_entity_id' => $patternData['plaid_entity_id'] ?? null,
                'plaid_entity_name' => $patternData['plaid_entity_name'] ?? null,
                'notes' => 'Generated from recurring pattern analysis and user confirmation.',
            ];
        } else {
            // Handle original pattern analysis data (backward compatibility)
            return [
                'budget_id' => $budget->id,
                'account_id' => $account->id,
                'description' => $patternData['description'],
                'category' => $patternData['category'],
                'amount_in_cents' => $patternData['amount_in_cents'],
                'frequency' => $patternData['frequency'],
                'start_date' => $patternData['suggested_start_date'],
                'end_date' => null,
                'day_of_month' => $patternData['day_of_month'],
                'day_of_week' => $patternData['day_of_week'],
                'first_day_of_month' => $patternData['first_day_of_month'],
                'is_dynamic_amount' => $patternData['is_dynamic_amount'],
                'average_amount' => $patternData['is_dynamic_amount'] ? ($patternData['amount_stats']['average'] / 100) : ($patternData['amount_in_cents'] / 100),
                'min_amount' => $patternData['is_dynamic_amount'] ? $patternData['amount_stats']['min'] : null,
                'max_amount' => $patternData['is_dynamic_amount'] ? $patternData['amount_stats']['max'] : null,
                'plaid_entity_id' => $patternData['plaid_entity_id'] ?? null,
                'plaid_entity_name' => $patternData['plaid_entity_name'] ?? null,
                'notes' => 'Generated from pattern analysis. Confidence: ' . number_format($patternData['confidence_score'] * 100, 1) . '%',
            ];
        }
    }

    /**
     * Link matching transactions to a template.
     */
    protected function linkMatchingTransactionsToTemplate(RecurringTransactionTemplate $template, array $patternData): int
    {
        // Check if this is confirmation data (from modal) - it won't have transaction data to link
        $isConfirmationData = isset($patternData['description']) && isset($patternData['frequency']) &&
                              !isset($patternData['suggested_start_date']) && !isset($patternData['confidence_score']);

        if ($isConfirmationData) {
            // For confirmation data, we don't link transactions since the user is manually creating templates
            // The user will need to link transactions manually or through a separate process
            return 0;
        }

        // Original logic for analysis-generated patterns
        if (!isset($patternData['transactions'])) {
            return 0;
        }

        $transactionIds = collect($patternData['transactions'])->pluck('id')->toArray();

        return Transaction::whereIn('id', $transactionIds)
            ->update(['recurring_transaction_template_id' => $template->id]);
    }

    /**
     * Enhance patterns with Plaid recurring transaction data (placeholder for future implementation).
     */
    protected function enhanceWithPlaidData($account, $patterns): array
    {
        // TODO: Integrate with Plaid's /transactions/recurring/get endpoint
        // This would cross-reference our detected patterns with Plaid's analysis
        // For now, just return the original patterns

        return $patterns;
    }

    // Helper methods

    /**
     * Get the original description from Plaid transaction or fall back to transaction description.
     * Prefers merchant_name if available, otherwise uses the raw Plaid name.
     */
    protected function getOriginalDescription(Transaction $transaction): string
    {
        if ($transaction->plaidTransaction) {
            // Prefer merchant_name as it's cleaner (e.g., "Amazon" vs "AMAZON MKTPLACE PMTS...")
            if (!empty($transaction->plaidTransaction->merchant_name)) {
                return $transaction->plaidTransaction->merchant_name;
            }
            // Fall back to the raw Plaid name
            if (!empty($transaction->plaidTransaction->name)) {
                return $transaction->plaidTransaction->name;
            }
        }
        
        // Fall back to the transaction's description
        return $transaction->description;
    }

    /**
     * Extract the primary Plaid entity ID and name from a collection of transactions.
     * Finds the most common entity across all transactions in the group.
     *
     * @param \Illuminate\Support\Collection $transactions
     * @return array{entity_id: string|null, entity_name: string|null}
     */
    protected function extractPrimaryEntityFromTransactions($transactions): array
    {
        $entityCounts = [];
        
        foreach ($transactions as $transaction) {
            if (!$transaction->plaidTransaction) {
                continue;
            }
            
            $counterparties = $transaction->plaidTransaction->counterparties;
            
            // Handle JSON string if not auto-decoded by Eloquent cast
            if (is_string($counterparties)) {
                $counterparties = json_decode($counterparties, true);
            }
            
            if (empty($counterparties) || !is_array($counterparties)) {
                continue;
            }
            
            // Get the first counterparty (usually the primary one)
            $primary = $counterparties[0] ?? null;
            if (!$primary || empty($primary['entity_id'])) {
                continue;
            }
            
            $entityId = $primary['entity_id'];
            if (!isset($entityCounts[$entityId])) {
                $entityCounts[$entityId] = [
                    'count' => 0,
                    'name' => $primary['name'] ?? null,
                ];
            }
            $entityCounts[$entityId]['count']++;
        }
        
        if (empty($entityCounts)) {
            return ['entity_id' => null, 'entity_name' => null];
        }
        
        // Find the most common entity
        uasort($entityCounts, fn($a, $b) => $b['count'] <=> $a['count']);
        $topEntityId = array_key_first($entityCounts);
        
        return [
            'entity_id' => $topEntityId,
            'entity_name' => $entityCounts[$topEntityId]['name'],
        ];
    }

    protected function normalizeDescription(string $description): string
    {
        // Remove transaction IDs, reference numbers, etc.
        $normalized = preg_replace('/\b\d{4,}\b/', '', $description); // Remove 4+ digit numbers
        $normalized = preg_replace('/\b[A-Z0-9]{6,}\b/', '', $normalized); // Remove long alphanumeric codes
        $normalized = preg_replace('/\s+/', ' ', $normalized); // Normalize whitespace
        $normalized = trim($normalized);

        // If normalization resulted in an empty string, fallback to original description
        if (empty($normalized)) {
            return trim($description);
        }

        return $normalized;
    }

    protected function generateDescriptionKey(string $normalizedDescription): string
    {
        // Create a key for grouping similar transactions
        $key = strtolower($normalizedDescription);
        $key = preg_replace('/[^a-z0-9\s]/', '', $key);
        $key = trim($key);
        return $key;
    }

    protected function calculateStandardDeviation(array $values): float
    {
        if (count($values) <= 1) {
            return 0;
        }

        $mean = array_sum($values) / count($values);
        $squaredDifferences = array_map(function($value) use ($mean) {
            return pow($value - $mean, 2);
        }, $values);

        $variance = array_sum($squaredDifferences) / count($values);
        return sqrt($variance);
    }

    protected function calculateVariance(array $values): float
    {
        if (count($values) <= 1) {
            return 0;
        }

        $mean = array_sum($values) / count($values);
        $squaredDifferences = array_map(function($value) use ($mean) {
            return pow($value - $mean, 2);
        }, $values);

        return array_sum($squaredDifferences) / count($values);
    }

    protected function findMostCommonDayOfMonth($dates): ?int
    {
        $days = $dates->map(function ($date) {
            return $date->day;
        });

        $dayCounts = $days->countBy();
        return $dayCounts->keys()->sortDesc()->first();
    }

    protected function findMostCommonDayOfWeek($dates): ?int
    {
        $days = $dates->map(function ($date) {
            return $date->dayOfWeek; // 0 = Sunday, 6 = Saturday
        });

        $dayCounts = $days->countBy();
        return $dayCounts->keys()->sortDesc()->first();
    }

    protected function isBimonthlyPattern($dates): bool
    {
        // Check if dates cluster around two specific days of the month
        $daysOfMonth = $dates->map(function ($date) {
            return $date->day;
        })->unique()->sort()->values();

        return $daysOfMonth->count() <= 3; // Allow some variation
    }

    protected function findBimonthlyDays($dates): array
    {
        $daysOfMonth = $dates->map(function ($date) {
            return $date->day;
        });

        $dayCounts = $daysOfMonth->countBy()->sortKeysDesc();
        $commonDays = $dayCounts->keys()->take(2)->sort()->values();

        return [
            'first' => $commonDays->get(0, 1),
            'second' => $commonDays->get(1, 15),
        ];
    }
}