<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRightIcon } from '@heroicons/vue/24/outline';
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
                                    <span class="flex-shrink-0 w-6 h-6 bg-blue-600 text-white rounded-full flex items-center justify-center text-sm font-bold mr-3">1</span>
                                    <div>
                                        <Link :href="route('budgets.index')" class="text-blue-600 dark:text-blue-400 hover:underline font-medium">
                                            Create your first budget
                                        </Link>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">Set up budgets to organize and track your finances</p>
                                    </div>
                                </li>
                                <li class="flex items-start">
                                    <span class="flex-shrink-0 w-6 h-6 bg-blue-600 text-white rounded-full flex items-center justify-center text-sm font-bold mr-3">2</span>
                                    <div>
                                        <span class="text-gray-700 dark:text-gray-300 font-medium">Connect your bank accounts</span>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">Link accounts via Plaid for automatic transaction syncing</p>
                                    </div>
                                </li>
                                <li class="flex items-start">
                                    <span class="flex-shrink-0 w-6 h-6 bg-blue-600 text-white rounded-full flex items-center justify-center text-sm font-bold mr-3">3</span>
                                    <div>
                                        <span class="text-gray-700 dark:text-gray-300 font-medium">Set up recurring transactions</span>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">Automate tracking of bills and regular income</p>
                                    </div>
                                </li>
                            </ul>
                        </div>

                        <!-- Quick Actions -->
                        <div>
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">Quick Actions</h2>
                            <div class="flex flex-wrap gap-3">
                                <Link
                                    :href="route('budgets.index')"
                                    class="inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-md transition-colors"
                                >
                                    View Budgets
                                    <ArrowRightIcon class="ml-2 w-4 h-4" />
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
