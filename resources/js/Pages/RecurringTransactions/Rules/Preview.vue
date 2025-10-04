<template>
    <Head :title="`Preview Rule Matches - ${recurringTransaction.description}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Preview Rule Matches - {{ recurringTransaction.description }}
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
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <div v-if="!hasActiveRules" class="bg-yellow-50 p-4 rounded-md">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <ExclamationIcon class="h-5 w-5 text-yellow-400" />
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-yellow-800">No active rules</h3>
                                <div class="mt-2 text-sm text-yellow-700">
                                    <p>You need to create and activate at least one rule before you can preview matches.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-else>
                        <div class="mb-6">
                            <h3 class="text-lg font-medium text-gray-900">Matching Transactions Preview</h3>
                            <p class="text-sm text-gray-600 mt-1">
                                These transactions would be linked if you apply the current active rules.
                                Showing unlinked transactions from the last 90 days.
                            </p>
                        </div>

                        <div v-if="matchingTransactions.length === 0" class="bg-blue-50 p-4 rounded-md">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <InformationCircleIcon class="h-5 w-5 text-blue-400" />
                                </div>
                                <div class="ml-3">
                                    <h3 class="text-sm font-medium text-blue-800">No matches found</h3>
                                    <div class="mt-2 text-sm text-blue-700">
                                        <p>No unlinked transactions match your current rules. This could mean:</p>
                                        <ul class="list-disc list-inside mt-2">
                                            <li>Your rules are too specific</li>
                                            <li>All matching transactions are already linked</li>
                                            <li>No matching transactions exist in the last 90 days</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-else>
                            <div class="mb-4 flex justify-between items-center">
                                <p class="text-sm text-gray-700">
                                    <span class="font-semibold">{{ matchingTransactions.length }}</span>
                                    transaction{{ matchingTransactions.length !== 1 ? 's' : '' }} would be linked
                                </p>
                                <button
                                    @click="applyRules"
                                    :disabled="isApplying"
                                    class="inline-flex items-center px-4 py-2 bg-green-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-700 active:bg-green-800 focus:outline-none focus:border-green-900 focus:ring focus:ring-green-300 disabled:opacity-25 transition"
                                >
                                    <CheckIcon class="w-4 h-4 mr-2" />
                                    {{ isApplying ? 'Applying...' : 'Apply Rules Now' }}
                                </button>
                            </div>

                            <div class="border rounded-lg overflow-hidden">
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
                                                Amount
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Category
                                            </th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Matched By
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <tr v-for="match in matchingTransactions" :key="match.transaction.id">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                {{ formatDate(match.transaction.date) }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                {{ match.transaction.description }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <span :class="match.transaction.amount_in_cents >= 0 ? 'text-green-600' : 'text-red-600'">
                                                    {{ formatCurrency(match.transaction.amount_in_cents) }}
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                {{ match.transaction.category || 'N/A' }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                                <div class="flex items-center">
                                                    <span class="inline-flex items-center px-2 py-1 text-xs font-medium bg-blue-100 text-blue-800 rounded">
                                                        Priority {{ match.matched_by_rule.priority }}
                                                    </span>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeftIcon, CheckIcon, ExclamationTriangleIcon as ExclamationIcon, InformationCircleIcon } from '@heroicons/vue/24/solid';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatCurrency } from '@/utils/format.js';

const props = defineProps({
    budget: Object,
    recurringTransaction: Object,
    matchingTransactions: Array,
    hasActiveRules: Boolean,
});

const isApplying = ref(false);

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString();
};

const applyRules = () => {
    isApplying.value = true;

    useForm().post(route('recurring-transactions.rules.apply', {
        budget: props.budget.id,
        recurring_transaction: props.recurringTransaction.id
    }), {
        onFinish: () => {
            isApplying.value = false;
        }
    });
};
</script>
