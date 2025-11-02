<script setup>
import { computed } from 'vue';
import { formatCurrency } from '@/utils/format.js';
import { ArrowUpIcon, ArrowDownIcon } from '@heroicons/vue/24/solid';

const props = defineProps({
    overview: Object,
    netWorth: Object,
    cashFlow: Object,
    budgetPerformance: Object,
    spendingPatterns: Object,
    debtPayoff: Object,
});

const formatPercent = (value) => {
    if (value === null || value === undefined || isNaN(value)) return '0%';
    return `${value >= 0 ? '+' : ''}${value.toFixed(1)}%`;
};

const netWorthChange = computed(() => {
    return props.overview?.netWorth?.changePercent || 0;
});

const savingsRate = computed(() => {
    return props.overview?.cashFlow?.savingsRate || 0;
});
</script>

<template>
    <div>
        <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">
            Financial Overview
        </h2>

        <!-- Key Metrics Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Net Worth -->
            <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-6">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                    Net Worth
                </div>
                <div class="text-2xl font-bold text-gray-900 dark:text-white mb-2">
                    {{ formatCurrency(overview?.netWorth?.current || 0) }}
                </div>
                <div class="flex items-center text-sm" :class="netWorthChange >= 0 ? 'text-green-600' : 'text-red-600'">
                    <ArrowUpIcon v-if="netWorthChange >= 0" class="w-4 h-4 mr-1" />
                    <ArrowDownIcon v-else class="w-4 h-4 mr-1" />
                    <span>{{ formatPercent(netWorthChange) }}</span>
                </div>
            </div>

            <!-- Assets -->
            <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-6">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                    Total Assets
                </div>
                <div class="text-2xl font-bold text-gray-900 dark:text-white">
                    {{ formatCurrency(overview?.assets || 0) }}
                </div>
            </div>

            <!-- Liabilities -->
            <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-6">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                    Total Liabilities
                </div>
                <div class="text-2xl font-bold text-gray-900 dark:text-white">
                    {{ formatCurrency(overview?.liabilities || 0) }}
                </div>
            </div>

            <!-- Savings Rate -->
            <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-6">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                    Savings Rate
                </div>
                <div class="text-2xl font-bold" :class="savingsRate >= 20 ? 'text-green-600' : 'text-gray-900 dark:text-white'">
                    {{ savingsRate.toFixed(1) }}%
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    {{ savingsRate >= 20 ? 'Excellent!' : savingsRate >= 10 ? 'Good progress' : 'Room to improve' }}
                </div>
            </div>
        </div>

        <!-- Cash Flow Summary -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <div class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg p-6">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">
                    Avg Monthly Income
                </div>
                <div class="text-xl font-bold text-green-600">
                    {{ formatCurrency(overview?.cashFlow?.avgMonthlyIncome || 0) }}
                </div>
            </div>

            <div class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg p-6">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">
                    Avg Monthly Expenses
                </div>
                <div class="text-xl font-bold text-red-600">
                    {{ formatCurrency(overview?.cashFlow?.avgMonthlyExpenses || 0) }}
                </div>
            </div>

            <div class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg p-6">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">
                    Net Cash Flow
                </div>
                <div class="text-xl font-bold" :class="(overview?.cashFlow?.avgMonthlyIncome - overview?.cashFlow?.avgMonthlyExpenses) >= 0 ? 'text-green-600' : 'text-red-600'">
                    {{ formatCurrency((overview?.cashFlow?.avgMonthlyIncome || 0) - (overview?.cashFlow?.avgMonthlyExpenses || 0)) }}
                </div>
            </div>
        </div>

        <!-- Quick Insights -->
        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-6">
            <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-100 mb-4">
                Quick Insights
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div v-if="netWorth?.summary" class="flex items-start space-x-3">
                    <div class="flex-shrink-0 w-2 h-2 mt-2 rounded-full bg-blue-500"></div>
                    <div>
                        <p class="text-sm text-gray-700 dark:text-gray-300">
                            Your net worth has
                            <span :class="netWorthChange >= 0 ? 'text-green-600 font-semibold' : 'text-red-600 font-semibold'">
                                {{ netWorthChange >= 0 ? 'increased' : 'decreased' }}
                            </span>
                            by {{ formatCurrency(Math.abs(netWorth.summary.change)) }} during this period
                        </p>
                    </div>
                </div>

                <div v-if="cashFlow?.summary" class="flex items-start space-x-3">
                    <div class="flex-shrink-0 w-2 h-2 mt-2 rounded-full bg-blue-500"></div>
                    <div>
                        <p class="text-sm text-gray-700 dark:text-gray-300">
                            Total income: {{ formatCurrency(cashFlow.summary.totalIncome) }},
                            Total expenses: {{ formatCurrency(cashFlow.summary.totalExpenses) }}
                        </p>
                    </div>
                </div>

                <div v-if="budgetPerformance?.summary" class="flex items-start space-x-3">
                    <div class="flex-shrink-0 w-2 h-2 mt-2 rounded-full bg-blue-500"></div>
                    <div>
                        <p class="text-sm text-gray-700 dark:text-gray-300">
                            <span v-if="budgetPerformance.summary.categoriesOverBudget > 0" class="text-red-600 font-semibold">
                                {{ budgetPerformance.summary.categoriesOverBudget }}
                            </span>
                            <span v-else class="text-green-600 font-semibold">No</span>
                            {{ budgetPerformance.summary.categoriesOverBudget === 1 ? 'category is' : 'categories are' }}
                            over budget
                        </p>
                    </div>
                </div>

                <div v-if="debtPayoff?.summary && debtPayoff.summary.numberOfDebts > 0" class="flex items-start space-x-3">
                    <div class="flex-shrink-0 w-2 h-2 mt-2 rounded-full bg-blue-500"></div>
                    <div>
                        <p class="text-sm text-gray-700 dark:text-gray-300">
                            You've paid off {{ formatCurrency(debtPayoff.summary.totalPaidOff) }}
                            in debt ({{ debtPayoff.summary.avgPayoffProgress.toFixed(1) }}% average progress)
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
