<script setup>
import { formatCurrency } from '@/utils/format.js';
import DebtProgressChart from '@/Components/Charts/DebtProgressChart.vue';

const props = defineProps({
    debtPayoff: Object,
});

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString(undefined, {
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        });
    } catch (e) {
        return dateString;
    }
};
</script>

<template>
    <div>
        <div v-if="(debtPayoff?.debts || []).length === 0" class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-8 text-center">
            <svg class="w-16 h-16 mx-auto text-blue-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">
                No Debt Accounts Found
            </h3>
            <p class="text-gray-600 dark:text-gray-400">
                You don't have any liability accounts. This is great news for your financial health!
            </p>
        </div>

        <div v-else>
            <!-- Summary Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-6">
                    <div class="text-sm font-medium text-red-700 dark:text-red-400 mb-1">
                        Total Debt
                    </div>
                    <div class="text-2xl font-bold text-red-900 dark:text-red-100">
                        {{ formatCurrency(debtPayoff?.summary?.totalDebt || 0) }}
                    </div>
                </div>

                <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-6">
                    <div class="text-sm font-medium text-green-700 dark:text-green-400 mb-1">
                        Total Paid Off
                    </div>
                    <div class="text-2xl font-bold text-green-900 dark:text-green-100">
                        {{ formatCurrency(debtPayoff?.summary?.totalPaidOff || 0) }}
                    </div>
                </div>

                <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-6">
                    <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                        Number of Debts
                    </div>
                    <div class="text-2xl font-bold text-gray-900 dark:text-white">
                        {{ debtPayoff?.summary?.numberOfDebts || 0 }}
                    </div>
                </div>

                <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-6">
                    <div class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">
                        Avg Progress
                    </div>
                    <div class="text-2xl font-bold text-gray-900 dark:text-white">
                        {{ (debtPayoff?.summary?.avgPayoffProgress || 0).toFixed(1) }}%
                    </div>
                </div>
            </div>

            <!-- Chart -->
            <div class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg p-6 mb-8">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                    Debt Payoff Progress by Account
                </h3>
                <DebtProgressChart :data="debtPayoff?.debts || []" :height="300" />
            </div>

            <!-- Debt Details -->
            <div class="space-y-6">
                <div
                    v-for="debt in debtPayoff?.debts || []"
                    :key="debt.accountName"
                    class="bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg overflow-hidden"
                >
                    <div class="p-6">
                        <div class="flex items-start justify-between mb-4">
                            <div>
                                <h4 class="text-lg font-semibold text-gray-900 dark:text-white">
                                    {{ debt.accountName }}
                                </h4>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                    {{ debt.accountType }}
                                </p>
                            </div>
                            <div class="text-right">
                                <div class="text-2xl font-bold text-gray-900 dark:text-white">
                                    {{ formatCurrency(debt.currentBalance) }}
                                </div>
                                <div class="text-sm text-gray-500 dark:text-gray-400">
                                    remaining
                                </div>
                            </div>
                        </div>

                        <!-- Progress Bar -->
                        <div class="mb-4">
                            <div class="flex items-center justify-between text-sm text-gray-600 dark:text-gray-400 mb-2">
                                <span>Progress</span>
                                <span class="font-semibold">{{ debt.percentPaidOff.toFixed(1) }}%</span>
                            </div>
                            <div class="w-full bg-gray-200 dark:bg-gray-600 rounded-full h-3">
                                <div
                                    class="h-3 rounded-full transition-all duration-300"
                                    :class="debt.percentPaidOff >= 75 ? 'bg-green-600' : debt.percentPaidOff >= 25 ? 'bg-yellow-500' : 'bg-red-500'"
                                    :style="{ width: `${Math.min(debt.percentPaidOff, 100)}%` }"
                                ></div>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div>
                                <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">
                                    Starting Balance
                                </div>
                                <div class="text-sm font-semibold text-gray-900 dark:text-white">
                                    {{ formatCurrency(debt.startBalance) }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">
                                    Paid Off
                                </div>
                                <div class="text-sm font-semibold text-green-600">
                                    {{ formatCurrency(debt.paidOff) }}
                                </div>
                            </div>
                            <div v-if="debt.hasPayoffPlan && debt.payoffPlan">
                                <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">
                                    Monthly Payment
                                </div>
                                <div class="text-sm font-semibold text-gray-900 dark:text-white">
                                    {{ formatCurrency(debt.payoffPlan.monthlyPayment) }}
                                </div>
                            </div>
                            <div v-if="debt.hasPayoffPlan && debt.payoffPlan">
                                <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">
                                    Target Date
                                </div>
                                <div class="text-sm font-semibold text-gray-900 dark:text-white">
                                    {{ formatDate(debt.payoffPlan.targetDate) }}
                                </div>
                            </div>
                        </div>

                        <div v-if="!debt.hasPayoffPlan" class="mt-4 p-3 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg">
                            <div class="flex items-start space-x-2">
                                <svg class="w-5 h-5 text-yellow-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                                <div>
                                    <p class="text-sm text-yellow-800 dark:text-yellow-400">
                                        No payoff plan set for this debt. Consider creating a payoff plan to track your progress and set a target date.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Insights -->
            <div class="mt-8 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-6">
                <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-100 mb-3">
                    Debt Payoff Insights
                </h3>
                <div class="space-y-3">
                    <div v-if="(debtPayoff?.summary?.avgPayoffProgress || 0) >= 50" class="flex items-start space-x-3">
                        <svg class="w-6 h-6 text-green-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <p class="text-gray-700 dark:text-gray-300">
                            Great progress! You're halfway or more through paying off your debts on average. Keep up the momentum!
                        </p>
                    </div>
                    <div v-else class="flex items-start space-x-3">
                        <svg class="w-6 h-6 text-blue-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <p class="text-gray-700 dark:text-gray-300">
                            You've made {{ (debtPayoff?.summary?.avgPayoffProgress || 0).toFixed(1) }}% progress on average. Consider using debt payoff strategies like the avalanche or snowball method to accelerate your payoff.
                        </p>
                    </div>
                    <div class="flex items-start space-x-3">
                        <div class="flex-shrink-0 w-2 h-2 mt-2 rounded-full bg-blue-500"></div>
                        <p class="text-gray-700 dark:text-gray-300">
                            You've paid off {{ formatCurrency(debtPayoff?.summary?.totalPaidOff || 0) }} in debt during this period.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
