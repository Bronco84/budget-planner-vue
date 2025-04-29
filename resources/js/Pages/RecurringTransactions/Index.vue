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
          <PrimaryButton as="a" :href="route('recurring-transactions.create', budget.id)">
            Add Recurring Transaction
          </PrimaryButton>
        </div>
      </div>
    </template>

    <div class="py-4">
      <div class="max-w-8xl mx-auto sm:px-2 lg:px-4">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <!-- Account filter selector -->
            <div class="mb-6">
              
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

            <div v-if="filteredTransactions.length > 0">
              <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                  <thead class="bg-gray-50">
                    <tr>
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
                      <th scope="col" class="relative px-6 py-3">
                        <span class="sr-only">Actions</span>
                      </th>
                    </tr>
                  </thead>
                  <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="template in filteredTransactions" :key="template.id">
                      <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900">{{ template.description }}</div>
                      </td>
                      <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm" :class="template.amount_in_cents >= 0 ? 'text-green-600' : 'text-red-600'">
                          {{ formatCurrency(template.amount_in_cents / 100) }}
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
                      <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <div class="flex space-x-2 justify-end">
                          <Link :href="route('recurring-transactions.edit', [budget.id, template.id])" class="text-indigo-600 hover:text-indigo-900">
                            Edit
                          </Link>
                          <Link :href="route('recurring-transactions.rules.index', [budget.id, template.id])" class="text-purple-600 hover:text-purple-900">
                            Rules
                          </Link>
                          <button @click="duplicate(template)" class="text-blue-600 hover:text-blue-900">
                            Duplicate
                          </button>
                          <button @click="confirmDelete(template)" class="text-red-600 hover:text-red-900">
                            Delete
                          </button>
                        </div>
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
import { ref, computed, onMounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
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

// Filtered transactions based on selected account
const filteredTransactions = computed(() => {
  console.log('Filtering transactions with selectedAccountId:', selectedAccountId.value, typeof selectedAccountId.value);
  
  if (selectedAccountId.value === 'all') {
    return props.recurringTransactions;
  }
  
  // Convert selectedAccountId to number for comparison
  const accountId = parseInt(selectedAccountId.value);
  console.log('Using accountId for filtering:', accountId, typeof accountId);
  
  return props.recurringTransactions.filter(transaction => {
    console.log('Transaction account_id:', transaction.account_id, typeof transaction.account_id);
    return transaction.account_id === accountId;
  });
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
        nextDate.setDate(dayOfMonth);
      }
      // Otherwise, it's next month
      else {
        nextDate.setMonth(nextDate.getMonth() + 1);
        nextDate.setDate(dayOfMonth);
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
        nextDate.setDate(quarterlyDayOfMonth);
      } else {
        // Move to the first month of the next quarter
        nextDate.setMonth(currentMonth + monthsUntilQuarterEnd + 1);
        nextDate.setDate(quarterlyDayOfMonth);
      }
      break;

    case 'yearly':
      // Occurs on the same day and month each year
      const startDate = new Date(template.start_date);
      nextDate = new Date();

      // Set to this year's occurrence
      nextDate.setMonth(startDate.getMonth());
      nextDate.setDate(startDate.getDate());

      // If this year's date has passed, go to next year
      if (nextDate < today) {
        nextDate.setFullYear(nextDate.getFullYear() + 1);
      }
      break;
  }

  return nextDate;
};
</script>
