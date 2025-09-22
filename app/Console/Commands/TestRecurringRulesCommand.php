<?php

namespace App\Console\Commands;

use App\Services\RecurringTransactionService;
use Illuminate\Console\Command;

class TestRecurringRulesCommand extends Command
{
    protected $signature = 'recurring:test-rules {id : The ID of the recurring transaction template to test}';
    protected $description = 'Test and analyze recurring transaction template rules with detailed output';

    protected $service;

    public function __construct(RecurringTransactionService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    public function handle()
    {
        $id = $this->argument('id');

        if (!is_numeric($id)) {
            $this->error("Invalid ID provided. Please provide a numeric ID.");
            return 1;
        }

        $template = $this->service->find($id);

        if (!$template) {
            $this->error("No recurring transaction template found for ID: {$id}");
            return 1;
        }

        $rules = $this->service->getRules($template);

        // Display template information
        $this->info("=== Recurring Transaction Template Analysis ===");
        $this->table(
            ['Property', 'Value'],
            [
                ['ID', $template->id],
                ['Description', $template->description],
                ['Category', $template->category ?? 'N/A'],
                ['Amount', '$' . number_format($template->amount_in_cents / 100, 2)],
                ['Frequency', $template->frequency],
                ['Is Dynamic Amount', $template->is_dynamic_amount ? 'Yes' : 'No'],
                ['Budget', $template->budget->name ?? 'N/A'],
                ['Account', $template->account->name ?? 'N/A'],
                ['Active Rules', $rules->count()]
            ]
        );

        if ($rules->isEmpty()) {
            $this->warn("No active rules found for this recurring transaction template.");
            
            if (!$template->is_dynamic_amount) {
                $this->info("This template uses a fixed amount and doesn't require rules.");
            } else {
                $this->error("This template is marked as dynamic amount but has no rules configured!");
            }
        }

        if ($rules->isNotEmpty()) {
            $this->newLine();
            $this->info("=== Rule Evaluation Results ===");

            $allPassed = true;
            $ruleResults = [];

            foreach ($rules as $index => $rule) {
                $result = $this->service->evaluateRule($template, $rule);
                
                $status = $result['passed'] ? '<info>✓ PASSED</info>' : '<error>✗ FAILED</error>';
                $matchInfo = "{$result['match_count']}/{$result['total_transactions']} matches ({$result['match_percentage']}%)";
                
                $ruleResults[] = [
                    $index + 1,
                    $result['description'],
                    $status,
                    $matchInfo
                ];
                
                if (!$result['passed']) {
                    $allPassed = false;
                }
            }

            $this->table(
                ['#', 'Rule Description', 'Status', 'Match Rate'],
                $ruleResults
            );

            // Show detailed results
            $this->newLine();
            $this->info("=== Detailed Rule Analysis ===");
            
            foreach ($rules as $index => $rule) {
                $result = $this->service->evaluateRule($template, $rule);
                
                $this->newLine();
                $this->line("<comment>Rule #" . ($index + 1) . ": {$result['description']}</comment>");
                $this->line($result['details']);
                
                if ($result['passed'] && $result['match_count'] > 0) {
                    $this->line("<info>✓ This rule successfully identifies transactions</info>");
                } elseif (!$result['passed']) {
                    $this->line("<error>✗ This rule found no matching transactions</error>");
                    $this->line("<error>  Consider adjusting the rule criteria</error>");
                }
            }
        } else {
            $allPassed = true; // No rules to fail
        }

        // Amount Analysis
        $this->newLine();
        $this->info("=== Amount Analysis ===");
        
        $amountAnalysis = $this->service->getAmountAnalysis($template);
        
        if (!$amountAnalysis['is_dynamic']) {
            $this->table(
                ['Property', 'Value'],
                [
                    ['Type', 'Fixed Amount'],
                    ['Amount', '$' . number_format($amountAnalysis['fixed_amount'] / 100, 2)],
                    ['Source', 'Template Configuration']
                ]
            );
        } else {
            $analysisRows = [
                ['Type', 'Dynamic Amount'],
                ['Rules Configured', $amountAnalysis['has_rules'] ? 'Yes (' . $amountAnalysis['rules_count'] . ')' : 'No'],
                ['Projected Amount', '$' . number_format($amountAnalysis['projected_amount'] / 100, 2)]
            ];
            
            if ($amountAnalysis['fallback_used']) {
                $fallbackType = $amountAnalysis['fallback_type'] === 'stored_average' ? 'Stored Average' : 'Template Default';
                $analysisRows[] = ['Calculation Method', 'Fallback (' . $fallbackType . ')'];
                
                if (isset($amountAnalysis['filtered_out_reason'])) {
                    $analysisRows[] = ['Fallback Reason', 'Min/Max constraints filtered out all matches'];
                } elseif ($amountAnalysis['matching_transactions_count'] === 0) {
                    $analysisRows[] = ['Fallback Reason', 'No transactions matched the rules'];
                } else {
                    $analysisRows[] = ['Fallback Reason', 'No rules configured'];
                }
            } else {
                $analysisRows[] = ['Calculation Method', 'Rule-based Average'];
                $analysisRows[] = ['Matching Transactions', $amountAnalysis['matching_transactions_count']];
                $analysisRows[] = ['Used in Calculation', $amountAnalysis['filtered_transactions_count']];
                
                if (isset($amountAnalysis['amount_stats'])) {
                    $stats = $amountAnalysis['amount_stats'];
                    $analysisRows[] = ['Average', '$' . number_format($stats['average'] / 100, 2)];
                    $analysisRows[] = ['Median', '$' . number_format($stats['median'] / 100, 2)];
                    $analysisRows[] = ['Range', '$' . number_format($stats['min'] / 100, 2) . ' to $' . number_format($stats['max'] / 100, 2)];
                    $analysisRows[] = ['Std Deviation', '$' . number_format($stats['std_dev'] / 100, 2)];
                }
            }
            
            // Add constraints if they exist
            if (isset($amountAnalysis['constraints'])) {
                $constraints = $amountAnalysis['constraints'];
                if ($constraints['min_amount']) {
                    $analysisRows[] = ['Min Amount Constraint', '$' . number_format($constraints['min_amount'] / 100, 2)];
                }
                if ($constraints['max_amount']) {
                    $analysisRows[] = ['Max Amount Constraint', '$' . number_format($constraints['max_amount'] / 100, 2)];
                }
                if ($constraints['stored_average']) {
                    $analysisRows[] = ['Stored Average', '$' . number_format($constraints['stored_average'], 2)];
                }
            }
            
            $this->table(['Property', 'Value'], $analysisRows);
            
            // Show detailed amount breakdown for rule-based calculations
            if (!$amountAnalysis['fallback_used'] && isset($amountAnalysis['amount_stats'])) {
                $this->newLine();
                $this->line("<comment>💰 Amount Calculation Details:</comment>");
                $stats = $amountAnalysis['amount_stats'];
                
                $this->line("  • Found {$amountAnalysis['matching_transactions_count']} transactions matching all rules");
                
                if ($amountAnalysis['matching_transactions_count'] !== $amountAnalysis['filtered_transactions_count']) {
                    $filtered = $amountAnalysis['matching_transactions_count'] - $amountAnalysis['filtered_transactions_count'];
                    $this->line("  • Filtered out {$filtered} transactions due to min/max constraints");
                }
                
                $this->line("  • Used {$amountAnalysis['filtered_transactions_count']} transactions for calculation");
                $this->line("  • Calculation Method: " . str_replace('_', ' ', ucwords($amountAnalysis['calculation_method'], '_')));
                $this->line("  • Average: $" . number_format($stats['average'] / 100, 2));
                $this->line("  • Median: $" . number_format($stats['median'] / 100, 2));
                $this->line("  • Standard Deviation: $" . number_format($stats['std_dev'] / 100, 2));
                
                // Show outlier information
                if (isset($amountAnalysis['outlier_analysis'])) {
                    $outlierCount = count($amountAnalysis['outlier_analysis']['outliers']);
                    if ($outlierCount > 0) {
                        $this->line("  • Outliers detected: {$outlierCount} transactions removed from calculation");
                    }
                }
                
                // Show trend information
                if (isset($amountAnalysis['trend_analysis'])) {
                    $trend = $amountAnalysis['trend_analysis'];
                    if ($trend['trend_direction'] !== 'stable') {
                        $monthlyChange = number_format(abs($trend['monthly_change_percent']), 1);
                        $direction = $trend['trend_direction'] === 'increasing' ? '📈' : '📉';
                        $this->line("  • Trend: {$direction} {$trend['trend_direction']} ({$monthlyChange}% monthly change)");
                        
                        if ($trend['trend_strength'] > 0.6) {
                            $this->line("    <comment>Strong trend - amounts may continue changing</comment>");
                        }
                    } else {
                        $this->line("  • Trend: 📊 Stable amounts over time");
                    }
                }
                
                $variance = $stats['std_dev'] / abs($stats['average']) * 100;
                if ($variance > 50) {
                    $this->line("  <error>⚠ High variance (" . number_format($variance, 1) . "%) - amounts are inconsistent</error>");
                } elseif ($variance > 25) {
                    $this->line("  <comment>⚠ Moderate variance (" . number_format($variance, 1) . "%) - some amount variation</comment>");
                } else {
                    $this->line("  <info>✓ Low variance (" . number_format($variance, 1) . "%) - amounts are consistent</info>");
                }
            }
            
            // Show confidence analysis
            if (isset($amountAnalysis['confidence'])) {
                $this->newLine();
                $confidence = $amountAnalysis['confidence'];
                $levelColor = $confidence['level'] === 'high' ? 'info' : ($confidence['level'] === 'medium' ? 'comment' : 'error');
                $icon = $confidence['level'] === 'high' ? '🟢' : ($confidence['level'] === 'medium' ? '🟡' : '🔴');
                
                $this->line("<{$levelColor}>🎯 Prediction Confidence: {$icon} " . ucfirst($confidence['level']) . " ({$confidence['score']}%)</{$levelColor}>");
                
                if (!empty($confidence['factors'])) {
                    $this->line("  <comment>Factors affecting confidence:</comment>");
                    foreach ($confidence['factors'] as $factor) {
                        $this->line("    • {$factor}");
                    }
                }
            }
        }
        
        // Summary
        $this->newLine();
        $this->info("=== Summary ===");
        
        if ($allPassed) {
            $this->info("✓ All rules are working correctly and finding matching transactions.");
        } else {
            $this->warn("⚠ Some rules are not finding any matching transactions.");
            $this->line("  This may indicate that the rule criteria need adjustment.");
        }
        
        if ($template->is_dynamic_amount) {
            if ($amountAnalysis['fallback_used']) {
                $this->line("💡 This template is using fallback amount calculation.");
                $this->line("   Consider reviewing rules or adding more historical transactions.");
            } else {
                $this->line("💡 This template successfully calculates amounts from rule matches.");
                $this->line("   Projected amount: $" . number_format($amountAnalysis['projected_amount'] / 100, 2));
            }
        }
        
        // Show improvement recommendations
        $this->newLine();
        $this->info("=== Improvement Recommendations ===");
        $this->showImprovementRecommendations($template, $amountAnalysis, $rules, $allPassed);

        return $allPassed ? 0 : 1;
    }
    
    /**
     * Show improvement recommendations based on analysis results
     */
    protected function showImprovementRecommendations($template, $amountAnalysis, $rules, $allPassed)
    {
        $recommendations = [];
        
        // Confidence-based recommendations
        if (isset($amountAnalysis['confidence'])) {
            $confidence = $amountAnalysis['confidence'];
            
            if ($confidence['level'] === 'low') {
                if (isset($confidence['metrics']['sample_size']) && $confidence['metrics']['sample_size'] < 6) {
                    $recommendations[] = [
                        'priority' => 'high',
                        'type' => 'Data Quality',
                        'issue' => 'Insufficient transaction history',
                        'recommendation' => 'Link more historical transactions to improve prediction accuracy',
                        'action' => 'Run: php artisan recurring:link-transactions ' . $template->id
                    ];
                }
                
                if (isset($confidence['metrics']['data_age_days']) && $confidence['metrics']['data_age_days'] > 90) {
                    $recommendations[] = [
                        'priority' => 'medium',
                        'type' => 'Data Freshness',
                        'issue' => 'Transaction data is outdated',
                        'recommendation' => 'Update rules to match more recent transaction patterns',
                        'action' => 'Review and adjust rule criteria'
                    ];
                }
            }
        }
        
        // Rule-specific recommendations
        if (!$allPassed) {
            $recommendations[] = [
                'priority' => 'high',
                'type' => 'Rule Effectiveness',
                'issue' => 'Some rules are not finding matching transactions',
                'recommendation' => 'Broaden rule criteria or check for typos in rule values',
                'action' => 'Edit rules to be less restrictive'
            ];
        }
        
        // Trend-based recommendations
        if (isset($amountAnalysis['trend_analysis'])) {
            $trend = $amountAnalysis['trend_analysis'];
            if ($trend['trend_strength'] > 0.6 && $trend['trend_direction'] !== 'stable') {
                $recommendations[] = [
                    'priority' => 'medium',
                    'type' => 'Trend Analysis',
                    'issue' => 'Strong ' . $trend['trend_direction'] . ' trend detected',
                    'recommendation' => 'Consider adjusting template amount or reviewing expense patterns',
                    'action' => 'Monitor and update amounts regularly'
                ];
            }
        }
        
        // Outlier recommendations
        if (isset($amountAnalysis['outlier_analysis']) && count($amountAnalysis['outlier_analysis']['outliers']) > 0) {
            $outlierCount = count($amountAnalysis['outlier_analysis']['outliers']);
            $totalCount = $amountAnalysis['filtered_transactions_count'] + $outlierCount;
            $outlierPercent = ($outlierCount / $totalCount) * 100;
            
            if ($outlierPercent > 20) {
                $recommendations[] = [
                    'priority' => 'medium',
                    'type' => 'Data Consistency',
                    'issue' => 'High number of outlier transactions (' . $outlierCount . ')',
                    'recommendation' => 'Review outlier transactions and consider tightening min/max constraints',
                    'action' => 'Adjust min_amount and max_amount settings'
                ];
            }
        }
        
        // Template configuration recommendations
        if ($template->is_dynamic_amount && $rules->isEmpty()) {
            $recommendations[] = [
                'priority' => 'high',
                'type' => 'Configuration',
                'issue' => 'Dynamic amount template has no rules',
                'recommendation' => 'Add rules to enable intelligent amount calculation',
                'action' => 'Create rules based on transaction description, category, or amount patterns'
            ];
        }
        
        // Min/Max constraint recommendations
        if ($template->is_dynamic_amount && 
            isset($amountAnalysis['matching_transactions_count']) && 
            isset($amountAnalysis['filtered_transactions_count']) &&
            $amountAnalysis['matching_transactions_count'] > $amountAnalysis['filtered_transactions_count']) {
            
            $filteredOut = $amountAnalysis['matching_transactions_count'] - $amountAnalysis['filtered_transactions_count'];
            $filteredPercent = ($filteredOut / $amountAnalysis['matching_transactions_count']) * 100;
            
            if ($filteredPercent > 40) {
                $recommendations[] = [
                    'priority' => 'medium',
                    'type' => 'Constraints',
                    'issue' => 'Min/max constraints filtering out too many transactions (' . $filteredOut . ')',
                    'recommendation' => 'Consider loosening amount constraints to include more data',
                    'action' => 'Adjust min_amount ($' . number_format($template->min_amount / 100, 2) . ') and max_amount ($' . number_format($template->max_amount / 100, 2) . ')'
                ];
            }
        }
        
        // Display recommendations
        if (empty($recommendations)) {
            $this->line("✅ <info>No issues detected! This template is well-configured.</info>");
            
            // Always show some general best practices
            $this->newLine();
            $this->line("<comment>💡 Best Practices:</comment>");
            $this->line("  • Review and update rules quarterly to maintain accuracy");
            $this->line("  • Monitor confidence scores and address low-confidence predictions");
            $this->line("  • Keep min/max constraints reasonable to avoid over-filtering");
            
        } else {
            // Sort by priority
            usort($recommendations, function($a, $b) {
                $priorities = ['high' => 3, 'medium' => 2, 'low' => 1];
                return $priorities[$b['priority']] - $priorities[$a['priority']];
            });
            
            foreach ($recommendations as $index => $rec) {
                $priorityColor = $rec['priority'] === 'high' ? 'error' : ($rec['priority'] === 'medium' ? 'comment' : 'info');
                $priorityIcon = $rec['priority'] === 'high' ? '🔴' : ($rec['priority'] === 'medium' ? '🟡' : '🟢');
                
                $this->newLine();
                $this->line("<{$priorityColor}>{$priorityIcon} {$rec['type']} ({$rec['priority']} priority)</{$priorityColor}>");
                $this->line("   Issue: {$rec['issue']}");
                $this->line("   Recommendation: {$rec['recommendation']}");
                $this->line("   Action: {$rec['action']}");
            }
        }
    }
}

