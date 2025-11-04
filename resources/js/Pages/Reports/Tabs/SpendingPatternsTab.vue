<script setup>
import { formatCurrency } from '@/utils/format.js';
import CategoryDoughnutChart from '@/Components/Charts/CategoryDoughnutChart.vue';

const props = defineProps({
    spendingPatterns: Object,
});
</script>

<template>
    <div>
        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-6">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                    Total Transactions
                </div>
                <div class="text-2xl font-bold text-gray-900 dark:text-white">
                    {{ spendingPatterns?.totalTransactions || 0 }}
                </div>
            </div>

            <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-6">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                    Total Spending
                </div>
                <div class="text-2xl font-bold text-gray-900 dark:text-white">
                    {{ formatCurrency(spendingPatterns?.totalSpending || 0) }}
                </div>
            </div>

            <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-6">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                    Avg Transaction
                </div>
                <div class="text-2xl font-bold text-gray-900 dark:text-white">
                    {{ formatCurrency(spendingPatterns?.averageTransaction || 0) }}
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Category Breakdown -->
            <div class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                    Top Spending Categories
                </h3>
                <CategoryDoughnutChart :data="spendingPatterns?.topCategories || []" :height="300" />
            </div>

            <!-- Merchant Breakdown -->
            <div class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                    Top Merchants
                </h3>
                <div v-if="(spendingPatterns?.topMerchants || []).length > 0" class="space-y-3">
                    <div
                        v-for="(merchant, index) in spendingPatterns?.topMerchants || []"
                        :key="merchant.merchant"
                        class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-600 rounded-lg"
                    >
                        <div class="flex items-center space-x-3">
                            <div class="flex-shrink-0 w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900/50 flex items-center justify-center">
                                <span class="text-sm font-bold text-blue-600 dark:text-blue-400">
                                    {{ index + 1 }}
                                </span>
                            </div>
                            <div>
                                <div class="text-sm font-medium text-gray-900 dark:text-white">
                                    {{ merchant.merchant }}
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ merchant.count }} {{ merchant.count === 1 ? 'transaction' : 'transactions' }}
                                </div>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm font-semibold text-gray-900 dark:text-white">
                                {{ formatCurrency(merchant.total) }}
                            </div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                {{ formatCurrency(merchant.average) }} avg
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else class="text-center py-8 text-gray-500 dark:text-gray-400">
                    No merchant data available. Connect to Plaid to see merchant insights.
                </div>
            </div>
        </div>

        <!-- Top Categories Table -->
        <div class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg overflow-hidden">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white p-6 pb-4">
                Category Spending Details
            </h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-600">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Rank
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Category
                            </th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Total Spent
                            </th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Transactions
                            </th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Avg Amount
                            </th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                % of Total
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-700 divide-y divide-gray-200 dark:divide-gray-600">
                        <tr v-for="(category, index) in spendingPatterns?.topCategories || []" :key="category.category">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                #{{ index + 1 }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                                {{ category.category }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900 dark:text-white">
                                {{ formatCurrency(category.total) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900 dark:text-white">
                                {{ category.count }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900 dark:text-white">
                                {{ formatCurrency(category.average) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900 dark:text-white">
                                {{ ((category.total / (spendingPatterns?.totalSpending || 1)) * 100).toFixed(1) }}%
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
