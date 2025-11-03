<script setup>
import { formatCurrency } from '@/utils/format.js';
import CashFlowChart from '@/Components/Charts/CashFlowChart.vue';

const props = defineProps({
    cashFlow: Object,
});
</script>

<template>
    <div>
        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-6">
                <div class="text-sm font-medium text-green-700 dark:text-green-400 mb-1">
                    Total Income
                </div>
                <div class="text-2xl font-bold text-green-900 dark:text-green-100">
                    {{ formatCurrency(cashFlow?.summary?.totalIncome || 0) }}
                </div>
            </div>

            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-6">
                <div class="text-sm font-medium text-red-700 dark:text-red-400 mb-1">
                    Total Expenses
                </div>
                <div class="text-2xl font-bold text-red-900 dark:text-red-100">
                    {{ formatCurrency(cashFlow?.summary?.totalExpenses || 0) }}
                </div>
            </div>

            <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-6">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                    Net Cash Flow
                </div>
                <div class="text-2xl font-bold" :class="(cashFlow?.summary?.netCashFlow || 0) >= 0 ? 'text-green-600' : 'text-red-600'">
                    {{ formatCurrency(cashFlow?.summary?.netCashFlow || 0) }}
                </div>
            </div>

            <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-6">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                    Savings Rate
                </div>
                <div class="text-2xl font-bold" :class="(cashFlow?.summary?.savingsRate || 0) >= 20 ? 'text-green-600' : 'text-gray-900 dark:text-white'">
                    {{ (cashFlow?.summary?.savingsRate || 0).toFixed(1) }}%
                </div>
            </div>
        </div>

        <!-- Chart -->
        <div class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg p-6 mb-8">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                Monthly Income vs Expenses
            </h3>
            <CashFlowChart :data="cashFlow" :height="400" />
        </div>

        <!-- Monthly Averages -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg p-6">
                <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">
                    Avg Monthly Income
                </h3>
                <div class="text-2xl font-bold text-green-600">
                    {{ formatCurrency(cashFlow?.summary?.avgMonthlyIncome || 0) }}
                </div>
            </div>

            <div class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg p-6">
                <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">
                    Avg Monthly Expenses
                </h3>
                <div class="text-2xl font-bold text-red-600">
                    {{ formatCurrency(cashFlow?.summary?.avgMonthlyExpenses || 0) }}
                </div>
            </div>

            <div class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg p-6">
                <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">
                    Avg Net Cash Flow
                </h3>
                <div class="text-2xl font-bold" :class="(cashFlow?.summary?.avgNetCashFlow || 0) >= 0 ? 'text-green-600' : 'text-red-600'">
                    {{ formatCurrency(cashFlow?.summary?.avgNetCashFlow || 0) }}
                </div>
            </div>
        </div>

        <!-- Insights -->
        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-6">
            <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-100 mb-3">
                Cash Flow Insights
            </h3>
            <div class="space-y-3">
                <div v-if="(cashFlow?.summary?.savingsRate || 0) >= 20" class="flex items-start space-x-3">
                    <svg class="w-6 h-6 text-green-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p class="text-gray-700 dark:text-gray-300">
                        Excellent savings rate! You're saving {{ (cashFlow?.summary?.savingsRate || 0).toFixed(1) }}% of your income, which is above the recommended 20%.
                    </p>
                </div>
                <div v-else-if="(cashFlow?.summary?.savingsRate || 0) >= 10" class="flex items-start space-x-3">
                    <svg class="w-6 h-6 text-blue-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p class="text-gray-700 dark:text-gray-300">
                        Good savings rate at {{ (cashFlow?.summary?.savingsRate || 0).toFixed(1) }}%. Consider aiming for 20% to accelerate wealth building.
                    </p>
                </div>
                <div v-else class="flex items-start space-x-3">
                    <svg class="w-6 h-6 text-red-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p class="text-gray-700 dark:text-gray-300">
                        Your savings rate is {{ (cashFlow?.summary?.savingsRate || 0).toFixed(1) }}%. Look for opportunities to reduce expenses or increase income to save more.
                    </p>
                </div>

                <div class="flex items-start space-x-3">
                    <div class="flex-shrink-0 w-2 h-2 mt-2 rounded-full bg-blue-500"></div>
                    <p class="text-gray-700 dark:text-gray-300">
                        On average, you earn {{ formatCurrency(cashFlow?.summary?.avgMonthlyIncome || 0) }} per month and spend {{ formatCurrency(cashFlow?.summary?.avgMonthlyExpenses || 0) }}.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
