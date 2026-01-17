<template>
  <Head title="Plaid Liabilities Management" />

  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
          Credit Card Statement Data
        </h2>
        <button
          @click="updateAll"
          :disabled="updating || !connections.length"
          class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          <svg v-if="updating" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          {{ updating ? 'Updating...' : 'Update All' }}
        </button>
      </div>
    </template>

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Info Banner -->
        <div class="mb-6 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
          <div class="flex">
            <div class="flex-shrink-0">
              <svg class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
              </svg>
            </div>
            <div class="ml-3">
              <p class="text-sm text-blue-700 dark:text-blue-300">
                This page allows you to manually sync statement balance data for your connected credit cards.
                Statement balances, payment due dates, and APR information are fetched from your bank via Plaid.
              </p>
            </div>
          </div>
        </div>

        <!-- No Connections Message -->
        <div v-if="!connections.length" class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6 text-center">
            <svg class="mx-auto h-12 w-12 text-gray-400 dark:text-gray-500 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
            </svg>
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">No Credit Cards Connected</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400">
              Connect a credit card account via Plaid to sync statement balance data.
            </p>
          </div>
        </div>

        <!-- Connections List -->
        <div v-else class="space-y-6">
          <div v-for="connection in connections" :key="connection.id" 
               class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6">
              <!-- Connection Header -->
              <div class="flex items-center justify-between mb-4">
                <div class="flex items-center space-x-3">
                  <img v-if="connection.institution_logo" 
                       :src="connection.institution_logo" 
                       :alt="connection.institution_name"
                       class="h-8 w-8 rounded">
                  <div v-else class="h-8 w-8 rounded bg-gray-200 dark:bg-gray-700 flex items-center justify-center">
                    <svg class="h-5 w-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                  </div>
                  <div>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                      {{ connection.institution_name }}
                    </h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                      {{ connection.budget_name }} • {{ connection.credit_cards.length }} credit card{{ connection.credit_cards.length !== 1 ? 's' : '' }}
                    </p>
                  </div>
                </div>
                <button
                  @click="updateConnection(connection.id)"
                  :disabled="updatingConnection === connection.id"
                  class="inline-flex items-center px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  <svg v-if="updatingConnection === connection.id" class="animate-spin -ml-1 mr-2 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                  <svg v-else class="h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                  </svg>
                  {{ updatingConnection === connection.id ? 'Syncing...' : 'Sync' }}
                </button>
              </div>

              <!-- Credit Cards Table -->
              <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                  <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                      <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Card</th>
                      <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Current Balance</th>
                      <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Statement Balance</th>
                      <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Statement Date</th>
                      <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Payment Due</th>
                      <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Min Payment</th>
                      <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">APR</th>
                      <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Credit Limit</th>
                    </tr>
                  </thead>
                  <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <tr v-for="card in connection.credit_cards" :key="card.id">
                      <td class="px-4 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ card.account_name }}</div>
                        <div v-if="card.account_mask" class="text-sm text-gray-500 dark:text-gray-400">•••• {{ card.account_mask }}</div>
                      </td>
                      <td class="px-4 py-4 whitespace-nowrap text-right text-sm text-gray-900 dark:text-gray-100">
                        {{ formatCurrency(card.current_balance_cents) }}
                      </td>
                      <td class="px-4 py-4 whitespace-nowrap text-right text-sm">
                        <span v-if="card.last_statement_balance_cents !== null" class="text-gray-900 dark:text-gray-100">
                          {{ formatCurrency(card.last_statement_balance_cents) }}
                        </span>
                        <span v-else class="text-gray-400 dark:text-gray-500 italic">Not available</span>
                      </td>
                      <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                        {{ card.last_statement_issue_date || '—' }}
                      </td>
                      <td class="px-4 py-4 whitespace-nowrap text-sm">
                        <span v-if="card.next_payment_due_date" :class="getDueDateClass(card.next_payment_due_date)">
                          {{ card.next_payment_due_date }}
                        </span>
                        <span v-else class="text-gray-400 dark:text-gray-500">—</span>
                      </td>
                      <td class="px-4 py-4 whitespace-nowrap text-right text-sm text-gray-500 dark:text-gray-400">
                        {{ card.minimum_payment_amount_cents !== null ? formatCurrency(card.minimum_payment_amount_cents) : '—' }}
                      </td>
                      <td class="px-4 py-4 whitespace-nowrap text-right text-sm text-gray-500 dark:text-gray-400">
                        {{ card.apr_percentage !== null ? `${card.apr_percentage}%` : '—' }}
                      </td>
                      <td class="px-4 py-4 whitespace-nowrap text-right text-sm text-gray-500 dark:text-gray-400">
                        {{ card.credit_limit_cents !== null ? formatCurrency(card.credit_limit_cents) : '—' }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <!-- Last Updated -->
              <div v-if="getLatestUpdate(connection)" class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                Last updated: {{ formatDate(getLatestUpdate(connection)) }}
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatCurrency } from '@/utils/format.js';
import { useToast } from '@/composables/useToast';

const toast = useToast();

const props = defineProps({
  connections: {
    type: Array,
    default: () => [],
  },
});

const updating = ref(false);
const updatingConnection = ref(null);

const updateConnection = (connectionId) => {
  updatingConnection.value = connectionId;
  router.post(route('admin.plaid-liabilities.update-connection', { connection: connectionId }), {}, {
    preserveScroll: true,
    onSuccess: (page) => {
      if (page.props.flash?.message) {
        toast.success(page.props.flash.message);
      } else if (page.props.flash?.error) {
        toast.error(page.props.flash.error);
      }
    },
    onError: (errors) => {
      if (errors.message) {
        toast.error(errors.message);
      }
    },
    onFinish: () => {
      updatingConnection.value = null;
    },
  });
};

const updateAll = () => {
  updating.value = true;
  router.post(route('admin.plaid-liabilities.update-all'), {}, {
    preserveScroll: true,
    onSuccess: (page) => {
      if (page.props.flash?.message) {
        toast.success(page.props.flash.message);
      } else if (page.props.flash?.error) {
        toast.error(page.props.flash.error);
      }
    },
    onError: (errors) => {
      if (errors.message) {
        toast.error(errors.message);
      }
    },
    onFinish: () => {
      updating.value = false;
    },
  });
};

const formatDate = (isoString) => {
  if (!isoString) return '';
  const date = new Date(isoString);
  return date.toLocaleString();
};

const getDueDateClass = (dueDate) => {
  if (!dueDate) return 'text-gray-500 dark:text-gray-400';
  
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const due = new Date(dueDate);
  due.setHours(0, 0, 0, 0);
  
  const daysUntilDue = Math.ceil((due - today) / (1000 * 60 * 60 * 24));
  
  if (daysUntilDue < 0) {
    return 'text-red-600 dark:text-red-400 font-medium'; // Overdue
  } else if (daysUntilDue <= 7) {
    return 'text-yellow-600 dark:text-yellow-400 font-medium'; // Due soon
  }
  return 'text-gray-500 dark:text-gray-400';
};

const getLatestUpdate = (connection) => {
  const updates = connection.credit_cards
    .map(card => card.liability_updated_at)
    .filter(Boolean);
  
  if (!updates.length) return null;
  return updates.sort().reverse()[0];
};
</script>
