<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { BanknotesIcon, ChartBarIcon, CalendarIcon, CurrencyDollarIcon } from '@heroicons/vue/24/outline';
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2
                class="text-xl font-semibold leading-tight text-gray-800"
            >
                Dashboard
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-lg font-medium mb-6">Welcome to Your Budget Planner</h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <!-- Budget Card -->
                        <Link :href="route('budgets.index')" class="block bg-white border rounded-lg shadow-sm hover:shadow-md transition-shadow p-5">
                            <div class="flex items-center justify-between">
                                <h4 class="text-lg font-medium">Budgets</h4>
                                <BanknotesIcon class="w-8 h-8 text-blue-500" />
                            </div>
                            <p class="text-gray-600 mt-2">Manage and track your various budgets</p>
                        </Link>
                        
                        <!-- Transactions Card -->
                        <div class="block bg-white border rounded-lg shadow-sm hover:shadow-md transition-shadow p-5">
                            <div class="flex items-center justify-between">
                                <h4 class="text-lg font-medium">Transactions</h4>
                                <CurrencyDollarIcon class="w-8 h-8 text-green-500" />
                            </div>
                            <p class="text-gray-600 mt-2">Track your income and expenses</p>
                        </div>
                        
                        <!-- Reports Card -->
                        <div class="block bg-white border rounded-lg shadow-sm hover:shadow-md transition-shadow p-5">
                            <div class="flex items-center justify-between">
                                <h4 class="text-lg font-medium">Reports</h4>
                                <ChartBarIcon class="w-8 h-8 text-purple-500" />
                            </div>
                            <p class="text-gray-600 mt-2">View reports and analysis of your finances</p>
                        </div>
                        
                        <!-- Calendar Card -->
                        <div class="block bg-white border rounded-lg shadow-sm hover:shadow-md transition-shadow p-5">
                            <div class="flex items-center justify-between">
                                <h4 class="text-lg font-medium">Calendar</h4>
                                <CalendarIcon class="w-8 h-8 text-red-500" />
                            </div>
                            <p class="text-gray-600 mt-2">See upcoming bills and income</p>
                        </div>
                    </div>
                    
                    <div class="mt-8 text-center">
                        <Link :href="route('budgets.index')" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150">
                            Get Started
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
