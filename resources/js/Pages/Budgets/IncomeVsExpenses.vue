<template>
  <Head :title="`Income vs Expenses - ${budget.name}`" />

  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
          {{ budget.name }} - Income vs Expenses
        </h2>
        <Link
          :href="route('budgets.show', budget.id)"
          class="px-3 py-1.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 flex items-center"
        >
          <ArrowLeftIcon class="w-4 h-4 mr-1" />
          Back to Budget
        </Link>
      </div>
    </template>

    <div class="py-8">
      <div class="max-w-8xl mx-auto sm:px-4 lg:px-6">
        <!-- Filter Bar -->
        <div class="mb-6 bg-white rounded-lg shadow-sm p-4">
          <div class="flex flex-col sm:flex-row sm:items-center gap-4">
            <div class="flex items-center gap-2">
              <FunnelIcon class="w-5 h-5 text-gray-400" />
              <label for="account-filter" class="text-sm font-medium text-gray-700">Filter by Account:</label>
            </div>
            <select
              id="account-filter"
              v-model="selectedAccountId"
              class="block w-full sm:w-64 py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
            >
              <option value="all">All Accounts</option>
              <option v-for="account in accounts" :key="account.id" :value="account.id">
                {{ account.name }}
              </option>
            </select>
            <div v-if="selectedAccountId !== 'all'" class="text-sm text-gray-500">
              Showing items for: <span class="font-medium text-gray-700">{{ selectedAccountName }}</span>
            </div>
          </div>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
          <div class="bg-white border rounded-lg shadow-sm p-5">
            <h3 class="text-sm font-medium text-gray-500 mb-2">Monthly Income</h3>
            <div class="text-2xl font-bold text-green-600">
              {{ formatCurrency(filteredTotals.monthly_income) }}
            </div>
          </div>

          <div class="bg-white border rounded-lg shadow-sm p-5">
            <h3 class="text-sm font-medium text-gray-500 mb-2">Monthly Expenses</h3>
            <div class="text-2xl font-bold text-red-600">
              {{ formatCurrency(filteredTotals.monthly_expenses) }}
            </div>
          </div>

          <div class="bg-white border rounded-lg shadow-sm p-5">
            <h3 class="text-sm font-medium text-gray-500 mb-2">Autopay Payments</h3>
            <div class="text-2xl font-bold text-orange-600">
              {{ formatCurrency(filteredTotals.monthly_autopay) }}
            </div>
          </div>

          <div class="bg-white border rounded-lg shadow-sm p-5">
            <h3 class="text-sm font-medium text-gray-500 mb-2">Net Monthly</h3>
            <div class="text-2xl font-bold" :class="filteredTotals.net >= 0 ? 'text-green-600' : 'text-red-600'">
              {{ formatCurrency(filteredTotals.net) }}
            </div>
          </div>
        </div>

        <!-- Side by Side Tables -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <!-- Income Table -->
          <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6">
              <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900 flex items-center">
                  <ArrowTrendingUpIcon class="w-5 h-5 mr-2 text-green-500" />
                  Income
                </h3>
                <span class="text-sm text-gray-500">{{ filteredIncomeItems.length }} items</span>
              </div>

              <div v-if="filteredIncomeItems.length > 0" class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                  <thead class="bg-gray-50">
                    <tr>
                      <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Description
                      </th>
                      <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Frequency
                      </th>
                      <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Monthly
                      </th>
                    </tr>
                  </thead>
                  <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="item in filteredIncomeItems" :key="item.id" class="hover:bg-gray-50">
                      <td class="px-4 py-3 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900">{{ item.description }}</div>
                        <div v-if="item.account_name" class="text-xs text-gray-500">{{ item.account_name }}</div>
                      </td>
                      <td class="px-4 py-3 whitespace-nowrap">
                        <span class="px-2 py-1 text-xs font-medium bg-gray-100 text-gray-700 rounded-full">
                          {{ formatFrequency(item.frequency) }}
                        </span>
                      </td>
                      <td class="px-4 py-3 whitespace-nowrap text-right">
                        <span class="text-sm font-semibold text-green-600">
                          {{ formatCurrency(item.monthly_amount) }}
                        </span>
                      </td>
                    </tr>
                  </tbody>
                  <tfoot class="bg-gray-50">
                    <tr>
                      <td colspan="2" class="px-4 py-3 text-sm font-semibold text-gray-900">
                        Total Monthly Income
                      </td>
                      <td class="px-4 py-3 text-right text-sm font-bold text-green-600">
                        {{ formatCurrency(filteredTotals.monthly_income) }}
                      </td>
                    </tr>
                  </tfoot>
                </table>
              </div>

              <div v-else class="text-center py-8 text-gray-500">
                <ArrowTrendingUpIcon class="w-12 h-12 mx-auto mb-3 text-gray-300" />
                <p>No recurring income found.</p>
                <Link
                  :href="route('recurring-transactions.create', budget.id)"
                  class="text-indigo-600 hover:text-indigo-500 text-sm font-medium mt-2 inline-block"
                >
                  Add recurring income
                </Link>
              </div>
            </div>
          </div>

          <!-- Expenses Table -->
          <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6">
              <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900 flex items-center">
                  <ArrowTrendingDownIcon class="w-5 h-5 mr-2 text-red-500" />
                  Expenses
                </h3>
                <span class="text-sm text-gray-500">{{ filteredExpenseItems.length + filteredAutopayItems.length }} items</span>
              </div>

              <div v-if="filteredExpenseItems.length > 0 || filteredAutopayItems.length > 0" class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                  <thead class="bg-gray-50">
                    <tr>
                      <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Description
                      </th>
                      <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Frequency
                      </th>
                      <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Monthly
                      </th>
                    </tr>
                  </thead>
                  <tbody class="bg-white divide-y divide-gray-200">
                    <!-- Regular Recurring Expenses -->
                    <tr v-for="item in filteredExpenseItems" :key="item.id" class="hover:bg-gray-50">
                      <td class="px-4 py-3 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900">{{ item.description }}</div>
                        <div v-if="item.account_name" class="text-xs text-gray-500">{{ item.account_name }}</div>
                      </td>
                      <td class="px-4 py-3 whitespace-nowrap">
                        <span class="px-2 py-1 text-xs font-medium bg-gray-100 text-gray-700 rounded-full">
                          {{ formatFrequency(item.frequency) }}
                        </span>
                      </td>
                      <td class="px-4 py-3 whitespace-nowrap text-right">
                        <span class="text-sm font-semibold text-red-600">
                          {{ formatCurrency(Math.abs(item.monthly_amount)) }}
                        </span>
                      </td>
                    </tr>

                    <!-- Autopay Section Header -->
                    <tr v-if="filteredAutopayItems.length > 0" class="bg-orange-50">
                      <td colspan="3" class="px-4 py-2">
                        <div class="flex items-center text-sm font-medium text-orange-700">
                          <CreditCardIcon class="w-4 h-4 mr-2" />
                          Credit Card Autopay Payments
                        </div>
                      </td>
                    </tr>

                    <!-- Autopay Items -->
                    <tr v-for="item in filteredAutopayItems" :key="'autopay-' + item.id" class="hover:bg-orange-50/50">
                      <td class="px-4 py-3 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900">{{ item.name }}</div>
                        <div class="text-xs text-gray-500">from {{ item.source_account_name }}</div>
                      </td>
                      <td class="px-4 py-3 whitespace-nowrap">
                        <span class="px-2 py-1 text-xs font-medium bg-orange-100 text-orange-700 rounded-full">
                          Monthly
                        </span>
                      </td>
                      <td class="px-4 py-3 whitespace-nowrap text-right">
                        <span class="text-sm font-semibold text-orange-600">
                          {{ formatCurrency(Math.abs(item.monthly_amount)) }}
                        </span>
                      </td>
                    </tr>
                  </tbody>
                  <tfoot class="bg-gray-50">
                    <tr v-if="filteredExpenseItems.length > 0">
                      <td colspan="2" class="px-4 py-2 text-sm text-gray-700">
                        Recurring Expenses
                      </td>
                      <td class="px-4 py-2 text-right text-sm font-semibold text-red-600">
                        {{ formatCurrency(filteredTotals.monthly_expenses) }}
                      </td>
                    </tr>
                    <tr v-if="filteredAutopayItems.length > 0">
                      <td colspan="2" class="px-4 py-2 text-sm text-gray-700">
                        Autopay Payments
                      </td>
                      <td class="px-4 py-2 text-right text-sm font-semibold text-orange-600">
                        {{ formatCurrency(filteredTotals.monthly_autopay) }}
                      </td>
                    </tr>
                    <tr class="border-t-2 border-gray-300">
                      <td colspan="2" class="px-4 py-3 text-sm font-semibold text-gray-900">
                        Total Monthly Expenses
                      </td>
                      <td class="px-4 py-3 text-right text-sm font-bold text-red-600">
                        {{ formatCurrency(filteredTotals.monthly_expenses + filteredTotals.monthly_autopay) }}
                      </td>
                    </tr>
                  </tfoot>
                </table>
              </div>

              <div v-else class="text-center py-8 text-gray-500">
                <ArrowTrendingDownIcon class="w-12 h-12 mx-auto mb-3 text-gray-300" />
                <p>No recurring expenses found.</p>
                <Link
                  :href="route('recurring-transactions.create', budget.id)"
                  class="text-indigo-600 hover:text-indigo-500 text-sm font-medium mt-2 inline-block"
                >
                  Add recurring expense
                </Link>
              </div>
            </div>
          </div>
        </div>

        <!-- Net Summary -->
        <div class="mt-6 bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <div class="flex items-center justify-between">
              <div>
                <h3 class="text-lg font-semibold text-gray-900">Monthly Summary</h3>
                <p class="text-sm text-gray-500 mt-1">
                  Based on your recurring transactions and autopay settings
                  <span v-if="selectedAccountId !== 'all'" class="text-indigo-600">
                    (filtered by {{ selectedAccountName }})
                  </span>
                </p>
              </div>
              <div class="text-right">
                <div class="text-sm text-gray-500">Net Monthly Cash Flow</div>
                <div
                  class="text-3xl font-bold"
                  :class="filteredTotals.net >= 0 ? 'text-green-600' : 'text-red-600'"
                >
                  {{ formatCurrency(filteredTotals.net) }}
                </div>
                <div class="text-sm mt-1" :class="filteredTotals.net >= 0 ? 'text-green-500' : 'text-red-500'">
                  {{ filteredTotals.net >= 0 ? 'Surplus' : 'Deficit' }} per month
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import {
  ArrowLeftIcon,
  ArrowTrendingUpIcon,
  ArrowTrendingDownIcon,
  CreditCardIcon,
  FunnelIcon,
} from '@heroicons/vue/24/outline';
import { formatCurrency } from '@/utils/format.js';

// Define props
const props = defineProps({
  budget: Object,
  accounts: Array,
  incomeItems: Array,
  expenseItems: Array,
  autopayItems: Array,
  totals: Object,
});

// Account filter state
const selectedAccountId = ref('all');

// Get selected account name for display
const selectedAccountName = computed(() => {
  if (selectedAccountId.value === 'all') return 'All Accounts';
  const account = props.accounts.find(a => a.id === selectedAccountId.value);
  return account?.name || 'Unknown';
});

// Filtered items based on selected account
const filteredIncomeItems = computed(() => {
  if (selectedAccountId.value === 'all') return props.incomeItems;
  return props.incomeItems.filter(item => item.account_id === selectedAccountId.value);
});

const filteredExpenseItems = computed(() => {
  if (selectedAccountId.value === 'all') return props.expenseItems;
  return props.expenseItems.filter(item => item.account_id === selectedAccountId.value);
});

const filteredAutopayItems = computed(() => {
  if (selectedAccountId.value === 'all') return props.autopayItems;
  // Autopay items: filter by either the credit card itself or the source account
  return props.autopayItems.filter(item => 
    item.id === selectedAccountId.value || 
    item.source_account_id === selectedAccountId.value
  );
});

// Recalculate totals based on filtered items
const filteredTotals = computed(() => {
  const monthlyIncome = filteredIncomeItems.value.reduce((sum, item) => sum + item.monthly_amount, 0);
  const monthlyExpenses = Math.abs(filteredExpenseItems.value.reduce((sum, item) => sum + item.monthly_amount, 0));
  const monthlyAutopay = Math.abs(filteredAutopayItems.value.reduce((sum, item) => sum + item.monthly_amount, 0));
  
  return {
    monthly_income: monthlyIncome,
    monthly_expenses: monthlyExpenses,
    monthly_autopay: monthlyAutopay,
    net: monthlyIncome - monthlyExpenses - monthlyAutopay,
  };
});

// Format frequency for display
const formatFrequency = (frequency) => {
  const frequencyMap = {
    daily: 'Daily',
    weekly: 'Weekly',
    biweekly: 'Biweekly',
    monthly: 'Monthly',
    bimonthly: 'Twice/Month',
    quarterly: 'Quarterly',
    yearly: 'Yearly',
  };
  return frequencyMap[frequency] || frequency;
};
</script>
