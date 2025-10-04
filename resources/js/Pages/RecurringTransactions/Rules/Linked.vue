<template>
    <Head :title="`Linked Transactions - ${recurringTransaction.description}`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                    Linked Transactions - {{ recurringTransaction.description }}
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
                    <div class="mb-6">
                        <h3 class="text-lg font-medium text-gray-900">Linked Transactions</h3>
                        <p class="text-sm text-gray-600 mt-1">
                            All transactions currently linked to this recurring transaction.
                            You can unlink any transaction if it was matched incorrectly.
                        </p>
                    </div>

                    <div v-if="linkedTransactions.data.length === 0" class="bg-gray-50 p-4 rounded-md">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <InformationCircleIcon class="h-5 w-5 text-gray-400" />
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-gray-800">No linked transactions</h3>
                                <div class="mt-2 text-sm text-gray-700">
                                    <p>No transactions are currently linked to this recurring transaction.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-else>
                        <div class="mb-4">
                            <p class="text-sm text-gray-700">
                                <span class="font-semibold">{{ linkedTransactions.total }}</span>
                                transaction{{ linkedTransactions.total !== 1 ? 's' : '' }} linked
                            </p>
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
                                            Account
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="transaction in linkedTransactions.data" :key="transaction.id">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ formatDate(transaction.date) }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ transaction.description }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <span :class="transaction.amount_in_cents >= 0 ? 'text-green-600' : 'text-red-600'">
                                                {{ formatCurrency(transaction.amount_in_cents) }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ transaction.category || 'N/A' }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            {{ transaction.account?.name || 'N/A' }}
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <button
                                                @click="confirmUnlink(transaction)"
                                                class="text-red-600 hover:text-red-900"
                                            >
                                                Unlink
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <div v-if="linkedTransactions.links.length > 3" class="mt-6">
                            <div class="flex justify-center">
                                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                                    <Link
                                        v-for="(link, index) in linkedTransactions.links"
                                        :key="index"
                                        :href="link.url"
                                        :class="[
                                            'relative inline-flex items-center px-4 py-2 border text-sm font-medium',
                                            link.active
                                                ? 'z-10 bg-indigo-50 border-indigo-500 text-indigo-600'
                                                : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50',
                                            !link.url ? 'cursor-not-allowed opacity-50' : 'cursor-pointer',
                                            index === 0 ? 'rounded-l-md' : '',
                                            index === linkedTransactions.links.length - 1 ? 'rounded-r-md' : ''
                                        ]"
                                        v-html="link.label"
                                    />
                                </nav>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Unlink Confirmation Modal -->
        <TransitionRoot appear :show="showUnlinkModal" as="template">
            <Dialog as="div" @close="showUnlinkModal = false" class="relative z-10">
                <TransitionChild
                    as="template"
                    enter="duration-300 ease-out"
                    enter-from="opacity-0"
                    enter-to="opacity-100"
                    leave="duration-200 ease-in"
                    leave-from="opacity-100"
                    leave-to="opacity-0"
                >
                    <div class="fixed inset-0 bg-black bg-opacity-25" />
                </TransitionChild>

                <div class="fixed inset-0 overflow-y-auto">
                    <div class="flex min-h-full items-center justify-center p-4 text-center">
                        <TransitionChild
                            as="template"
                            enter="duration-300 ease-out"
                            enter-from="opacity-0 scale-95"
                            enter-to="opacity-100 scale-100"
                            leave="duration-200 ease-in"
                            leave-from="opacity-100 scale-100"
                            leave-to="opacity-0 scale-95"
                        >
                            <DialogPanel class="w-full max-w-md transform overflow-hidden rounded-2xl bg-white p-6 text-left align-middle shadow-xl transition-all">
                                <DialogTitle as="h3" class="text-lg font-medium leading-6 text-gray-900">
                                    Unlink Transaction
                                </DialogTitle>
                                <div class="mt-2">
                                    <p class="text-sm text-gray-500">
                                        Are you sure you want to unlink this transaction from the recurring transaction?
                                        The transaction will remain in your budget but will no longer be associated with this recurring transaction.
                                    </p>
                                </div>

                                <div class="mt-4 flex justify-end space-x-3">
                                    <button
                                        type="button"
                                        class="inline-flex justify-center rounded-md border border-transparent bg-gray-100 px-4 py-2 text-sm font-medium text-gray-900 hover:bg-gray-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-500 focus-visible:ring-offset-2"
                                        @click="showUnlinkModal = false"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex justify-center rounded-md border border-transparent bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2"
                                        @click="unlinkTransaction"
                                        :disabled="isUnlinking"
                                    >
                                        {{ isUnlinking ? 'Unlinking...' : 'Unlink' }}
                                    </button>
                                </div>
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </div>
            </Dialog>
        </TransitionRoot>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Dialog, DialogPanel, DialogTitle, TransitionRoot, TransitionChild } from '@headlessui/vue';
import { ArrowLeftIcon, InformationCircleIcon } from '@heroicons/vue/24/solid';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatCurrency } from '@/utils/format.js';

const props = defineProps({
    budget: Object,
    recurringTransaction: Object,
    linkedTransactions: Object,
});

const showUnlinkModal = ref(false);
const transactionToUnlink = ref(null);
const isUnlinking = ref(false);

const formatDate = (dateString) => {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString();
};

const confirmUnlink = (transaction) => {
    transactionToUnlink.value = transaction;
    showUnlinkModal.value = true;
};

const unlinkTransaction = () => {
    isUnlinking.value = true;

    useForm({
        transaction_id: transactionToUnlink.value.id
    }).post(route('recurring-transactions.rules.unlink', {
        budget: props.budget.id,
        recurring_transaction: props.recurringTransaction.id
    }), {
        onFinish: () => {
            isUnlinking.value = false;
            showUnlinkModal.value = false;
            transactionToUnlink.value = null;
        }
    });
};
</script>