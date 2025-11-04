<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { CheckCircleIcon } from '@heroicons/vue/24/solid';

defineProps({
    gettingStarted: Object,
});
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-8">
                    <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-4">Welcome to Your Budget Planner</h1>
                    <p class="text-lg text-gray-600 dark:text-gray-300 mb-8">
                        Manage your finances, track spending, and plan for the future all in one place.
                    </p>

                    <div class="space-y-6">
                        <!-- Getting Started Section -->
                        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-6">
                            <h2 class="text-xl font-semibold text-blue-900 dark:text-blue-100 mb-3">Getting Started</h2>
                            <ul class="space-y-3">
                                <li class="flex items-start">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center text-sm font-bold mr-3"
                                        :class="gettingStarted.hasBudget
                                            ? 'bg-green-600 text-white'
                                            : 'bg-blue-600 text-white'"
                                    >
                                        <CheckCircleIcon v-if="gettingStarted.hasBudget" class="w-5 h-5" />
                                        <span v-else>1</span>
                                    </span>
                                    <div class="flex-1">
                                        <Link
                                            :href="route('budgets.index')"
                                            class="font-medium"
                                            :class="gettingStarted.hasBudget
                                                ? 'text-green-700 dark:text-green-400 hover:underline'
                                                : 'text-blue-600 dark:text-blue-400 hover:underline'"
                                        >
                                            Create your first budget
                                        </Link>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">
                                            Set up budgets to organize and track your finances
                                        </p>
                                        <span
                                            v-if="gettingStarted.hasBudget"
                                            class="inline-flex items-center text-xs font-medium text-green-700 dark:text-green-400 mt-1"
                                        >
                                            <CheckCircleIcon class="w-4 h-4 mr-1" />
                                            Completed
                                        </span>
                                    </div>
                                </li>
                                <li class="flex items-start">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center text-sm font-bold mr-3"
                                        :class="gettingStarted.hasPlaidAccounts
                                            ? 'bg-green-600 text-white'
                                            : 'bg-blue-600 text-white'"
                                    >
                                        <CheckCircleIcon v-if="gettingStarted.hasPlaidAccounts" class="w-5 h-5" />
                                        <span v-else>2</span>
                                    </span>
                                    <div class="flex-1">
                                        <span
                                            class="font-medium"
                                            :class="gettingStarted.hasPlaidAccounts
                                                ? 'text-green-700 dark:text-green-400'
                                                : 'text-gray-700 dark:text-gray-300'"
                                        >
                                            Connect your bank accounts
                                        </span>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">
                                            Link accounts via Plaid for automatic transaction syncing
                                        </p>
                                        <span
                                            v-if="gettingStarted.hasPlaidAccounts"
                                            class="inline-flex items-center text-xs font-medium text-green-700 dark:text-green-400 mt-1"
                                        >
                                            <CheckCircleIcon class="w-4 h-4 mr-1" />
                                            Completed
                                        </span>
                                    </div>
                                </li>
                                <li class="flex items-start">
                                    <span
                                        class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center text-sm font-bold mr-3"
                                        :class="gettingStarted.hasRecurringTransactions
                                            ? 'bg-green-600 text-white'
                                            : 'bg-blue-600 text-white'"
                                    >
                                        <CheckCircleIcon v-if="gettingStarted.hasRecurringTransactions" class="w-5 h-5" />
                                        <span v-else>3</span>
                                    </span>
                                    <div class="flex-1">
                                        <span
                                            class="font-medium"
                                            :class="gettingStarted.hasRecurringTransactions
                                                ? 'text-green-700 dark:text-green-400'
                                                : 'text-gray-700 dark:text-gray-300'"
                                        >
                                            Set up recurring transactions
                                        </span>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">
                                            Automate tracking of bills and regular income
                                        </p>
                                        <span
                                            v-if="gettingStarted.hasRecurringTransactions"
                                            class="inline-flex items-center text-xs font-medium text-green-700 dark:text-green-400 mt-1"
                                        >
                                            <CheckCircleIcon class="w-4 h-4 mr-1" />
                                            Completed
                                        </span>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>