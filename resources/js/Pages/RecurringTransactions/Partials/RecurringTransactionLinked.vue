<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <p class="text-sm text-gray-600">
        All transactions currently linked to this recurring transaction. You can unlink any transaction if it was matched incorrectly.
      </p>
      <div v-if="linkedTransactions.length > 0" class="text-sm text-gray-700 font-medium">
        <span class="font-semibold">{{ linkedTransactions.length }}</span> transaction{{ linkedTransactions.length !== 1 ? 's' : '' }} linked
      </div>
    </div>

    <div v-if="linkedTransactions.length === 0" class="bg-gray-50 border border-gray-200 rounded-md p-8 text-center">
      <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
      </svg>
      <h3 class="mt-2 text-sm font-medium text-gray-900">No linked transactions</h3>
      <p class="mt-1 text-sm text-gray-500">
        No transactions are currently linked to this recurring transaction.
      </p>
    </div>

    <div v-else class="border rounded-lg overflow-hidden">
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
          <tr v-for="transaction in linkedTransactions" :key="transaction.id">
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

    <!-- Summary Stats -->
    <div v-if="linkedTransactions.length > 0" class="border-t border-gray-200 pt-6">
      <h4 class="text-md font-medium text-gray-900 mb-4">Summary</h4>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-gray-50 p-4 rounded-lg">
          <div class="text-sm text-gray-600">Total Linked</div>
          <div class="text-2xl font-bold text-gray-900">{{ linkedTransactions.length }}</div>
        </div>
        <div class="bg-gray-50 p-4 rounded-lg">
          <div class="text-sm text-gray-600">Combined Amount</div>
          <div class="text-2xl font-bold" :class="totalAmount >= 0 ? 'text-green-600' : 'text-red-600'">
            {{ formatCurrency(totalAmount) }}
          </div>
        </div>
        <div class="bg-gray-50 p-4 rounded-lg">
          <div class="text-sm text-gray-600">Average Amount</div>
          <div class="text-2xl font-bold" :class="averageAmount >= 0 ? 'text-green-600' : 'text-red-600'">
            {{ formatCurrency(averageAmount) }}
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
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Dialog, DialogPanel, DialogTitle, TransitionRoot, TransitionChild } from '@headlessui/vue';
import { formatCurrency } from '@/utils/format.js';

const props = defineProps({
  budget: Object,
  recurringTransaction: Object,
  linkedTransactions: Array,
});

const showUnlinkModal = ref(false);
const transactionToUnlink = ref(null);
const isUnlinking = ref(false);

const totalAmount = computed(() => {
  return props.linkedTransactions.reduce((sum, t) => sum + t.amount_in_cents, 0);
});

const averageAmount = computed(() => {
  if (props.linkedTransactions.length === 0) return 0;
  return totalAmount.value / props.linkedTransactions.length;
});

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
