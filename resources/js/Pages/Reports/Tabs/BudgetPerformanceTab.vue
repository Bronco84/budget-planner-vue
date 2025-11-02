<script setup>
import { formatCurrency } from '@/utils/format.js';
import BudgetVarianceChart from '@/Components/Charts/BudgetVarianceChart.vue';

const props = defineProps({
    budgetPerformance: Object,
});
</script>

<template>
    <div>
        <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">
            Budget Performance
        </h2>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-6">
                <div class="text-sm font-medium text-blue-700 dark:text-blue-400 mb-1">
                    Total Allocated
                </div>
                <div class="text-2xl font-bold text-blue-900 dark:text-blue-100">
                    {{ formatCurrency(budgetPerformance?.summary?.totalAllocated || 0) }}
                </div>
            </div>

            <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-6">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                    Total Spent
                </div>
                <div class="text-2xl font-bold text-gray-900 dark:text-white">
                    {{ formatCurrency(budgetPerformance?.summary?.totalSpent || 0) }}
                </div>
            </div>

            <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-6">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                    Remaining
                </div>
                <div class="text-2xl font-bold" :class="(budgetPerformance?.summary?.totalRemaining || 0) >= 0 ? 'text-green-600' : 'text-red-600'">
                    {{ formatCurrency(budgetPerformance?.summary?.totalRemaining || 0) }}
                </div>
            </div>

            <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-6">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                    Over Budget
                </div>
                <div class="text-2xl font-bold" :class="(budgetPerformance?.summary?.categoriesOverBudget || 0) > 0 ? 'text-red-600' : 'text-green-600'">
                    {{ budgetPerformance?.summary?.categoriesOverBudget || 0 }}
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    {{ (budgetPerformance?.summary?.categoriesOverBudget || 0) === 1 ? 'category' : 'categories' }}
                </div>
            </div>
        </div>

        <!-- Chart -->
        <div class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg p-6 mb-8">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                Budget vs Actual by Category
            </h3>
            <BudgetVarianceChart :data="budgetPerformance?.categories || []" :height="Math.max(400, (budgetPerformance?.categories?.length || 0) * 60)" />
        </div>

        <!-- Category Details Table -->
        <div class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg overflow-hidden">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white p-6 pb-4">
                Category Breakdown
            </h3>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-600">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Category
                            </th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Allocated
                            </th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Spent
                            </th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Remaining
                            </th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                % Used
                            </th>
                            <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Status
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-700 divide-y divide-gray-200 dark:divide-gray-600">
                        <tr v-for="category in budgetPerformance?.categories || []" :key="category.category">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div v-if="category.color" class="w-3 h-3 rounded-full mr-2" :style="{ backgroundColor: category.color }"></div>
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ category.category }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900 dark:text-white">
                                {{ formatCurrency(category.allocated) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900 dark:text-white">
                                {{ formatCurrency(category.spent) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm" :class="category.remaining >= 0 ? 'text-green-600' : 'text-red-600'">
                                {{ formatCurrency(category.remaining) }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-900 dark:text-white">
                                {{ category.percentUsed.toFixed(1) }}%
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <span v-if="category.isOverBudget" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-400">
                                    Over Budget
                                </span>
                                <span v-else-if="category.percentUsed >= 90" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400">
                                    Near Limit
                                </span>
                                <span v-else class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400">
                                    On Track
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
