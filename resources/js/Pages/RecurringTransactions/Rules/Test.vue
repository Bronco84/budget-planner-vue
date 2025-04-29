<template>
    <Head :title="'Test Rule'" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Test Results: {{ rule.value }}
                </h2>
                <Link
                    :href="route('recurring-transactions.rules.index', { budget: budget.id, recurring_transaction: recurringTransaction.id })"
                    class="inline-flex items-center px-4 py-2 bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-300 active:bg-gray-400 focus:outline-none focus:border-gray-900 focus:ring focus:ring-gray-300 disabled:opacity-25 transition"
                >
                    <ArrowLeftIcon class="w-4 h-4 mr-2" />
                    Back to Rules
                </Link>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="mb-6 bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Rule Details</h3>
                    <div class="bg-gray-50 p-4 rounded-md">
                        <p class="text-sm text-gray-700">
                            <span class="font-medium">{{ getFieldLabel(rule.field) }}</span>
                            <span class="mx-1">{{ getOperatorLabel(rule.operator) }}</span>
                            <span class="font-medium">"{{ rule.value }}"</span>
                            <span v-if="rule.is_case_sensitive" class="ml-2 text-xs text-gray-500">(Case Sensitive)</span>
                        </p>
                    </div>
                </div>
                
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-lg font-medium text-gray-900">
                            Matching Transactions
                            <span class="ml-2 text-sm text-gray-500">
                                ({{ matchingTransactions.length }} of {{ totalTested }} tested)
                            </span>
                        </h3>
                        <div class="flex space-x-3">
                            <Link
                                :href="route('recurring-transactions.rules.store', { budget: budget.id, recurring_transaction: recurringTransaction.id })"
                                method="post"
                                :data="rule"
                                class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 active:bg-blue-800 focus:outline-none focus:border-blue-900 focus:ring focus:ring-blue-300 disabled:opacity-25 transition"
                            >
                                Save Rule
                            </Link>
                        </div>
                    </div>

                    <div v-if="matchingTransactions.length === 0" class="bg-yellow-50 p-4 rounded-md">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <ExclamationIcon class="h-5 w-5 text-yellow-400" />
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-yellow-800">No matching transactions found</h3>
                                <div class="mt-2 text-sm text-yellow-700">
                                    <p>
                                        Your rule didn't match any recent transactions. You might want to adjust it to make it less specific.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-else>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Date
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Description
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Category
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Amount
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="transaction in matchingTransactions" :key="transaction.id">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ formatDate(transaction.date) }}
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-900">
                                            {{ transaction.description }}
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500">
                                            {{ transaction.category }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm"
                                            :class="{ 'text-green-600': transaction.amount_in_cents > 0, 'text-red-600': transaction.amount_in_cents < 0 }">
                                            {{ formatAmount(transaction.amount_in_cents) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script>
import { Link, Head } from '@inertiajs/vue3';
import { ArrowLeftIcon, ExclamationTriangleIcon as ExclamationIcon } from '@heroicons/vue/24/solid';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

export default {
    components: {
        Head,
        AuthenticatedLayout,
        Link,
        ArrowLeftIcon,
        ExclamationIcon
    },
    
    props: {
        budget: Object,
        recurringTransaction: Object,
        rule: Object,
        matchingTransactions: Array,
        totalTested: Number
    },
    
    methods: {
        formatDate(dateString) {
            return new Date(dateString).toLocaleDateString();
        },
        
        formatAmount(amountInCents) {
            const amount = Math.abs(amountInCents / 100);
            return '$' + amount.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
        },
        
        getFieldLabel(field) {
            const fieldLabels = {
                'description': 'Description',
                'amount': 'Amount',
                'category': 'Category'
            };
            return fieldLabels[field] || field;
        },
        
        getOperatorLabel(operator) {
            const operatorLabels = {
                'contains': 'Contains',
                'equals': 'Equals',
                'starts_with': 'Starts With',
                'ends_with': 'Ends With',
                'regex': 'Matches Regex',
                'greater_than': 'Greater Than',
                'less_than': 'Less Than'
            };
            return operatorLabels[operator] || operator;
        }
    }
}
</script> 