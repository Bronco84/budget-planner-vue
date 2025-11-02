<script setup>
import { formatCurrency } from '@/utils/format.js';
import NetWorthLineChart from '@/Components/Charts/NetWorthLineChart.vue';
import { ArrowUpIcon, ArrowDownIcon } from '@heroicons/vue/24/solid';

const props = defineProps({
    netWorth: Object,
});

const formatPercent = (value) => {
    if (value === null || value === undefined || isNaN(value)) return '0%';
    return `${value >= 0 ? '+' : ''}${value.toFixed(1)}%`;
};
</script>

<template>
    <div>
        <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">
            Net Worth Tracking
        </h2>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-gradient-to-br from-green-50 to-green-100 dark:from-green-900/20 dark:to-green-800/20 border border-green-200 dark:border-green-800 rounded-lg p-6">
                <div class="text-sm font-medium text-green-700 dark:text-green-400 mb-1">
                    Current Net Worth
                </div>
                <div class="text-2xl font-bold text-green-900 dark:text-green-100">
                    {{ formatCurrency(netWorth?.summary?.currentNetWorth || 0) }}
                </div>
            </div>

            <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-6">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                    Starting Net Worth
                </div>
                <div class="text-2xl font-bold text-gray-900 dark:text-white">
                    {{ formatCurrency(netWorth?.summary?.startingNetWorth || 0) }}
                </div>
            </div>

            <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-6">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                    Change
                </div>
                <div class="text-2xl font-bold" :class="(netWorth?.summary?.change || 0) >= 0 ? 'text-green-600' : 'text-red-600'">
                    {{ formatCurrency(netWorth?.summary?.change || 0) }}
                </div>
            </div>

            <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-6">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                    % Change
                </div>
                <div class="flex items-center">
                    <div class="text-2xl font-bold mr-2" :class="(netWorth?.summary?.changePercent || 0) >= 0 ? 'text-green-600' : 'text-red-600'">
                        {{ formatPercent(netWorth?.summary?.changePercent || 0) }}
                    </div>
                    <ArrowUpIcon v-if="(netWorth?.summary?.changePercent || 0) >= 0" class="w-6 h-6 text-green-600" />
                    <ArrowDownIcon v-else class="w-6 h-6 text-red-600" />
                </div>
            </div>
        </div>

        <!-- Chart -->
        <div class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg p-6 mb-8">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                Net Worth Over Time
            </h3>
            <NetWorthLineChart :data="netWorth" :height="400" />
        </div>

        <!-- Assets & Liabilities Breakdown -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg p-6">
                <h3 class="text-lg font-semibold text-blue-600 mb-4">
                    Assets
                </h3>
                <div class="text-3xl font-bold text-gray-900 dark:text-white mb-2">
                    {{ formatCurrency(netWorth?.summary?.currentAssets || 0) }}
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Total value of all your asset accounts (checking, savings, investments, etc.)
                </p>
            </div>

            <div class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg p-6">
                <h3 class="text-lg font-semibold text-red-600 mb-4">
                    Liabilities
                </h3>
                <div class="text-3xl font-bold text-gray-900 dark:text-white mb-2">
                    {{ formatCurrency(netWorth?.summary?.currentLiabilities || 0) }}
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Total value of all your liability accounts (credit cards, loans, mortgages, etc.)
                </p>
            </div>
        </div>

        <!-- Financial Health Indicator -->
        <div class="mt-8 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-6">
            <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-100 mb-3">
                Financial Health
            </h3>
            <div v-if="(netWorth?.summary?.changePercent || 0) > 0" class="flex items-start space-x-3">
                <svg class="w-6 h-6 text-green-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <p class="text-gray-700 dark:text-gray-300">
                    Excellent! Your net worth is growing. Continue building your assets and paying down liabilities.
                </p>
            </div>
            <div v-else-if="(netWorth?.summary?.changePercent || 0) === 0" class="flex items-start space-x-3">
                <svg class="w-6 h-6 text-blue-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <p class="text-gray-700 dark:text-gray-300">
                    Your net worth is stable. Consider finding opportunities to increase income or reduce expenses to grow your wealth.
                </p>
            </div>
            <div v-else class="flex items-start space-x-3">
                <svg class="w-6 h-6 text-red-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <p class="text-gray-700 dark:text-gray-300">
                    Your net worth has decreased. Review your spending and look for ways to increase income or reduce expenses.
                </p>
            </div>
        </div>
    </div>
</template>
