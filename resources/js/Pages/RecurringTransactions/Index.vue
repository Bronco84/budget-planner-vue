<template>
  <Head :title="budget.name + ' - Recurring Transactions'" />

  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ budget.name }} - Recurring Transactions</h2>
        <div class="flex items-center space-x-3">
          <Link
            :href="route('budgets.show', budget.id)"
            class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm transition duration-150 ease-in-out hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25"
          >
            Back to Budget
          </Link>
          <Link 
            :href="route('budget.transaction.create', budget.id)"
            class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm transition duration-150 ease-in-out hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25"
          >
            <svg class="-ml-1 mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
            </svg>
            Create Transaction
          </Link>
          <Link 
            :href="route('recurring-transactions.create', budget.id)"
            class="inline-flex items-center rounded-md border border-transparent bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-gray-700 focus:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 active:bg-gray-900"
          >
            Add Recurring Transaction
          </Link>
        </div>
      </div>
    </template>

    <div class="py-4">
      <div class="max-w-8xl mx-auto sm:px-2 lg:px-4 overflow-x-auto">
        <div class="bg-white shadow-sm sm:rounded-lg">
          <div class="p-6">
            <!-- Filter selectors -->
            <div class="mb-6 flex flex-col sm:flex-row sm:space-x-4 space-y-4 sm:space-y-0">
              <div>
                <label for="account-filter" class="block text-sm font-medium text-gray-700">Filter by Account</label>
                <select
                  id="account-filter"
                  v-model="selectedAccountId"
                  class="mt-1 block w-full sm:w-64 py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                >
                  <option value="all">All Accounts</option>
                  <option v-for="account in accountsToUse" :key="account.id" :value="account.id">
                    {{ account.name }}
                  </option>
                </select>
              </div>

              <div>
                <label for="amount-type-filter" class="block text-sm font-medium text-gray-700">Amount Type</label>
                <select
                  id="amount-type-filter"
                  v-model="selectedAmountType"
                  class="mt-1 block w-full sm:w-48 py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                >
                  <option value="all">All Types</option>
                  <option value="fixed">Fixed Amount</option>
                  <option value="variable">Variable Amount</option>
                </select>
              </div>
            </div>

            <!-- Summary stats -->
            <div v-if="props.recurringTransactions.length > 0" class="mb-4 flex flex-wrap gap-4">
              <div class="inline-flex items-center px-3 py-2 bg-gray-50 rounded-lg text-sm text-gray-700">
                <svg class="w-4 h-4 mr-2 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                </svg>
                Total: {{ props.recurringTransactions.length }}
              </div>
              <div class="inline-flex items-center px-3 py-2 bg-blue-50 rounded-lg text-sm text-blue-700">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                </svg>
                Variable: {{ variableTransactionsCount }}
              </div>
              <div class="inline-flex items-center px-3 py-2 bg-gray-100 rounded-lg text-sm text-gray-700">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                </svg>
                Fixed: {{ fixedTransactionsCount }}
              </div>
              <div v-if="filteredTransactions.length !== props.recurringTransactions.length" class="inline-flex items-center px-3 py-2 bg-indigo-50 rounded-lg text-sm text-indigo-700">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path>
                </svg>
                Showing: {{ filteredTransactions.length }}
              </div>
            </div>

            <div v-if="filteredTransactions.length > 0">
              <div class="overflow-x-auto border rounded-lg">
                <table class="min-w-full divide-y divide-gray-200">
                  <thead class="bg-gray-50 sticky top-0">
                    <tr>
                      <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Created
                      </th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Description
                      </th>
                      <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Amount
                      </th>
                      <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Frequency
                      </th>
                      <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Category
                      </th>
                      <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Account
                      </th>
                      <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Next Date
                      </th>
                      <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Actions
                      </th>
                    </tr>
                  </thead>
                  <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="template in filteredTransactions" :key="template.id">
                      <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900">{{ formatDate(template.created_at) }}</div>
                      </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900">{{ template.description }}</div>
                      </td>
                      <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center space-x-2">
                          <div class="text-sm" :class="template.amount_in_cents >= 0 ? 'text-green-600' : 'text-red-600'">
                            {{ formatCurrency(template.amount_in_cents) }}
                          </div>
                          <!-- Variable amount indicator -->
                          <div v-if="template.is_dynamic_amount" class="inline-flex items-center px-2 py-1 text-xs font-medium bg-blue-100 text-blue-800 rounded-full" title="Variable amount based on rules">
                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                            </svg>
                            Variable
                          </div>
                          <div v-else class="inline-flex items-center px-2 py-1 text-xs font-medium bg-gray-100 text-gray-800 rounded-full" title="Fixed amount">
                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                            </svg>
                            Fixed
                          </div>
                        </div>
                      </td>
                      <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-900 capitalize">{{ template.frequency }}</div>
                      </td>
                      <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-900">{{ template.category || 'Not specified' }}</div>
                      </td>
                      <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-900">{{ template.account?.name || 'Not specified' }}</div>
                      </td>
                      <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-900">{{ formatDate(getNextOccurrence(template)) }}</div>
                      </td>
                      <td class="px-6 py-4 whitespace-nowrap text-left text-sm font-medium">
                        <Dropdown align="left" width="48">
                          <template #trigger>
                            <button type="button" class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-md text-sm leading-4 font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                              Actions
                              <svg class="-mr-1 ml-2 h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                              </svg>
                            </button>
                          </template>
                          <template #content>
                            <DropdownLink :href="createTransactionUrl(template)">
                              <div class="flex items-center">
                                <svg class="w-4 h-4 mr-2 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                </svg>
                                Create Transaction
                              </div>
                            </DropdownLink>
                            <DropdownLink :href="route('recurring-transactions.edit', [budget.id, template.id])">
                              <div class="flex items-center">
                                <svg class="w-4 h-4 mr-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                </svg>
                                Edit Template
                              </div>
                            </DropdownLink>
                            <DropdownLink :href="route('recurring-transactions.rules.index', [budget.id, template.id])">
                              <div class="flex items-center">
                                <svg class="w-4 h-4 mr-2 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                </svg>
                                Manage Rules
                              </div>
                            </DropdownLink>
                            <button @click="duplicate(template)" class="block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 transition duration-150 ease-in-out hover:bg-gray-100 focus:bg-gray-100 focus:outline-none">
                              <div class="flex items-center">
                                <svg class="w-4 h-4 mr-2 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                                </svg>
                                Duplicate
                              </div>
                            </button>
                            <button @click="confirmDelete(template)" class="block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 transition duration-150 ease-in-out hover:bg-gray-100 focus:bg-gray-100 focus:outline-none">
                              <div class="flex items-center">
                                <svg class="w-4 h-4 mr-2 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                </svg>
                                Delete
                              </div>
                            </button>
                          </template>
                        </Dropdown>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
            <div v-else class="text-center py-10">
              <p class="text-gray-500">No recurring transactions have been set up yet.</p>
              <p class="mt-2 text-sm text-gray-400">
                Recurring transactions help you plan for regular income and expenses.
              </p>
              <Link
                :href="route('recurring-transactions.create', budget.id)"
                class="mt-4 inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500"
              >
                Add Your First Recurring Transaction
              </Link>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed, onMounted, nextTick } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import { formatCurrency } from '@/utils/format.js';

const props = defineProps({
  budget: Object,
  recurringTransactions: Array,
  accounts: Array,
});

// Debug accounts on mount
onMounted(() => {
  console.log('Budget object:', props.budget);
  console.log('Direct accounts received:', props.accounts);
  console.log('Budget.accounts:', props.budget.accounts);
  console.log('Recurring transactions:', props.recurringTransactions);
});

// Determine which accounts source to use
const accountsToUse = computed(() => {
  if (props.accounts && props.accounts.length > 0) {
    return props.accounts;
  } else if (props.budget.accounts && props.budget.accounts.length > 0) {
    return props.budget.accounts;
  }
  return [];
});

const accountSource = computed(() => {
  if (props.accounts && props.accounts.length > 0) {
    return 'direct accounts prop';
  } else if (props.budget.accounts && props.budget.accounts.length > 0) {
    return 'budget.accounts';
  }
  return 'none';
});

// Account filtering
const selectedAccountId = ref('all');

// Amount type filtering
const selectedAmountType = ref('all');

// Filtered transactions based on selected account and amount type
const filteredTransactions = computed(() => {
  let filtered = props.recurringTransactions;

  // Filter by account
  if (selectedAccountId.value !== 'all') {
    const accountId = parseInt(selectedAccountId.value);
    filtered = filtered.filter(transaction => transaction.account_id === accountId);
  }

  // Filter by amount type
  if (selectedAmountType.value !== 'all') {
    filtered = filtered.filter(transaction => {
      if (selectedAmountType.value === 'variable') {
        return transaction.is_dynamic_amount;
      } else if (selectedAmountType.value === 'fixed') {
        return !transaction.is_dynamic_amount;
      }
      return true;
    });
  }

  return filtered;
});

// Count statistics
const variableTransactionsCount = computed(() => {
  return props.recurringTransactions.filter(t => t.is_dynamic_amount).length;
});

const fixedTransactionsCount = computed(() => {
  return props.recurringTransactions.filter(t => !t.is_dynamic_amount).length;
});

const confirmDelete = (template) => {
  if (confirm(`Are you sure you want to delete the recurring transaction "${template.description}"?`)) {
    router.delete(route('recurring-transactions.destroy', [props.budget.id, template.id]));
  }
};

const duplicate = (template) => {
  if (confirm(`Do you want to duplicate the recurring transaction "${template.description}"?`)) {
    router.post(route('recurring-transactions.duplicate', [props.budget.id, template.id]));
  }
};

const formatDate = (dateString) => {
  if (!dateString) return 'N/A';
  const date = new Date(dateString);
  return date.toLocaleDateString();
};

// Calculate the next occurrence of a recurring transaction
const getNextOccurrence = (template) => {
  if (!template.start_date) return null;

  const today = new Date();
  let nextDate = new Date(template.start_date);

  // If the template has ended, return null
  if (template.end_date && new Date(template.end_date) < today) {
    return null;
  }

  // If the start date is in the future, that's the next date
  if (nextDate > today) {
    return nextDate;
  }

  // Helper function to get the last day of a month
  const getDaysInMonth = (date) => {
    return new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate();
  };

  // Helper function to set date safely (handles day-of-month edge cases)
  // Examples: 31st → Feb 28th/29th, 30th → Feb 28th/29th, 29th → Feb 28th (non-leap years)
  const setSafeDayOfMonth = (date, targetDay) => {
    const daysInMonth = getDaysInMonth(date);
    const safeDay = Math.min(targetDay, daysInMonth);
    date.setDate(safeDay);
    return date;
  };

  // Otherwise, calculate the next occurrence based on frequency
  switch (template.frequency) {
    case 'daily':
      // Next day
      nextDate = new Date();
      nextDate.setDate(today.getDate() + 1);
      break;

    case 'weekly':
      // Find the next occurrence of day_of_week
      const dayOfWeek = template.day_of_week;
      let daysToAdd = (dayOfWeek - today.getDay() + 7) % 7;
      if (daysToAdd === 0) daysToAdd = 7; // If today is the day, go to next week

      nextDate = new Date();
      nextDate.setDate(today.getDate() + daysToAdd);
      break;

    case 'biweekly':
      // Similar to weekly but add 14 days
      const biweeklyDayOfWeek = template.day_of_week;
      let biweeklyDaysToAdd = (biweeklyDayOfWeek - today.getDay() + 7) % 7;
      if (biweeklyDaysToAdd === 0) biweeklyDaysToAdd = 14; // If today is the day, go to next week
      else biweeklyDaysToAdd += 7; // Add another week for biweekly

      nextDate = new Date();
      nextDate.setDate(today.getDate() + biweeklyDaysToAdd);
      break;

    case 'monthly':
      // Find the next occurrence of day_of_month
      const dayOfMonth = template.day_of_month || new Date(template.start_date).getDate();
      nextDate = new Date();

      // If today's date is before the day of month, it occurs this month
      if (today.getDate() < dayOfMonth) {
        setSafeDayOfMonth(nextDate, dayOfMonth);
      }
      // Otherwise, it's next month
      else {
        nextDate.setMonth(nextDate.getMonth() + 1);
        setSafeDayOfMonth(nextDate, dayOfMonth);
      }
      break;

    case 'bimonthly':
      // Twice per month (e.g., 1st and 15th)
      const firstDay = template.first_day_of_month || 1;
      const secondDay = template.day_of_month || 15;
      
      // Ensure first day is before second day
      const actualFirstDay = Math.min(firstDay, secondDay);
      const actualSecondDay = Math.max(firstDay, secondDay);
      
      nextDate = new Date();
      const currentDay = today.getDate();
      
      // If we're before the first day of the month, move to the first day
      if (currentDay < actualFirstDay) {
        setSafeDayOfMonth(nextDate, actualFirstDay);
      }
      // If we're between the first and second days, move to the second day
      else if (currentDay < actualSecondDay) {
        setSafeDayOfMonth(nextDate, actualSecondDay);
      }
      // Otherwise, we're past both days this month, move to the first day of next month
      else {
        nextDate.setMonth(nextDate.getMonth() + 1);
        setSafeDayOfMonth(nextDate, actualFirstDay);
      }
      break;

    case 'quarterly':
      // Similar to monthly but every 3 months
      const quarterlyDayOfMonth = template.day_of_month || new Date(template.start_date).getDate();
      nextDate = new Date();

      // Calculate months to add (0-2 for current quarter, 3 for next quarter)
      const currentMonth = today.getMonth();
      const monthsUntilQuarterEnd = 2 - (currentMonth % 3);

      if (today.getDate() < quarterlyDayOfMonth && monthsUntilQuarterEnd === 2) {
        // If it's the first month of the quarter and day hasn't passed
        setSafeDayOfMonth(nextDate, quarterlyDayOfMonth);
      } else {
        // Move to the first month of the next quarter
        nextDate.setMonth(currentMonth + monthsUntilQuarterEnd + 1);
        setSafeDayOfMonth(nextDate, quarterlyDayOfMonth);
      }
      break;

    case 'yearly':
      // Occurs on the same day and month each year
      const startDate = new Date(template.start_date);
      nextDate = new Date();

      // Set to this year's occurrence
      nextDate.setMonth(startDate.getMonth());
      setSafeDayOfMonth(nextDate, startDate.getDate());

      // If this year's date has passed, go to next year
      if (nextDate < today) {
        nextDate.setFullYear(nextDate.getFullYear() + 1);
        nextDate.setMonth(startDate.getMonth());
        setSafeDayOfMonth(nextDate, startDate.getDate());
      }
      break;
  }

  return nextDate;
};

// Create transaction URL with pre-filled data
const createTransactionUrl = (template) => {
  const nextDate = getNextOccurrence(template);
  const nextDateString = nextDate ? nextDate.toISOString().split('T')[0] : '';
  
  const params = new URLSearchParams({
    description: template.description || '',
    account_id: template.account_id || '',
    category: template.category || '',
    date: nextDateString,
    recurring_transaction_template_id: template.id,
    amount: Math.abs(template.amount_in_cents / 100).toString() // Convert to positive dollars for easier editing
  });
  
  return route('budget.transaction.create', props.budget.id) + '?' + params.toString();
};
</script>
