<script setup>
import { ref, computed, reactive } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
  ArrowTrendingUpIcon,
  ArrowTrendingDownIcon,
  CreditCardIcon,
  XMarkIcon,
  PlusIcon,
} from '@heroicons/vue/24/outline';
import { formatCurrency } from '@/utils/format.js';

// Define props - receives data from Reports/Index.vue
const props = defineProps({
  budget: Object,
  incomeVsExpenses: Object,
});

// Extract data from props
const incomeItems = computed(() => props.incomeVsExpenses?.incomeItems || []);
const expenseItems = computed(() => props.incomeVsExpenses?.expenseItems || []);
const autopayItems = computed(() => props.incomeVsExpenses?.autopayItems || []);
const accounts = computed(() => props.incomeVsExpenses?.accounts || []);

// Account filter state
const selectedAccountId = ref('all');

// Simulation mode toggle
const simulationMode = ref(false);

// Adjustments tracking - stores the adjusted values in cents
const adjustments = reactive({
  income: {},    // { itemId: adjustedAmountInCents }
  expense: {},   // { itemId: adjustedAmountInCents }
  autopay: {},   // { itemId: adjustedAmountInCents }
});

// Hypothetical items - for adding simulated income/expenses
const hypotheticalItems = reactive({
  income: [],   // [{ id, description, amount_cents, frequency, monthly_amount }]
  expense: [],  // [{ id, description, amount_cents, frequency, monthly_amount }]
});

// Form state for adding hypothetical items
const showAddIncomeForm = ref(false);
const showAddExpenseForm = ref(false);
const newIncomeItem = reactive({ description: '', amount: '', frequency: 'monthly' });
const newExpenseItem = reactive({ description: '', amount: '', frequency: 'monthly' });

let hypotheticalIdCounter = 1;

// Get the selected account ID as a number for comparison (or null if 'all')
const selectedAccountIdNum = computed(() => {
  if (selectedAccountId.value === 'all') return null;
  return parseInt(selectedAccountId.value, 10);
});

// Get selected account name for display
const selectedAccountName = computed(() => {
  if (selectedAccountId.value === 'all') return 'All Accounts';
  const account = accounts.value.find(a => a.id === selectedAccountIdNum.value);
  return account?.name || 'Unknown';
});

// Filtered items based on selected account
const filteredIncomeItems = computed(() => {
  if (selectedAccountIdNum.value === null) return incomeItems.value;
  return incomeItems.value.filter(item => item.account_id === selectedAccountIdNum.value);
});

const filteredExpenseItems = computed(() => {
  if (selectedAccountIdNum.value === null) return expenseItems.value;
  return expenseItems.value.filter(item => item.account_id === selectedAccountIdNum.value);
});

const filteredAutopayItems = computed(() => {
  if (selectedAccountIdNum.value === null) return autopayItems.value;
  return autopayItems.value.filter(item => 
    item.id === selectedAccountIdNum.value || 
    item.source_account_id === selectedAccountIdNum.value
  );
});

// Check if there are any adjustments or hypothetical items
const hasAdjustments = computed(() => {
  return Object.keys(adjustments.income).length > 0 ||
         Object.keys(adjustments.expense).length > 0 ||
         Object.keys(adjustments.autopay).length > 0 ||
         hypotheticalItems.income.length > 0 ||
         hypotheticalItems.expense.length > 0;
});

// Get adjusted amount for an item
const getAdjustedAmount = (type, itemId, originalAmount) => {
  if (adjustments[type][itemId] !== undefined) {
    return adjustments[type][itemId];
  }
  return originalAmount;
};

// Check if an item has been adjusted
const hasItemAdjustment = (type, itemId) => {
  return adjustments[type][itemId] !== undefined;
};

// Update an adjustment (for income - positive values)
const updateAdjustment = (type, itemId, event) => {
  const value = parseFloat(event.target.value);
  if (!isNaN(value)) {
    adjustments[type][itemId] = Math.round(value * 100); // Convert to cents
  }
};

// Update an expense adjustment (stores as negative cents)
const updateExpenseAdjustment = (type, itemId, event) => {
  const value = parseFloat(event.target.value);
  if (!isNaN(value)) {
    adjustments[type][itemId] = -Math.round(Math.abs(value) * 100); // Convert to negative cents
  }
};

// Clear a specific item adjustment
const clearItemAdjustment = (type, itemId) => {
  delete adjustments[type][itemId];
};

// Reset all adjustments and hypothetical items
const resetAdjustments = () => {
  adjustments.income = {};
  adjustments.expense = {};
  adjustments.autopay = {};
  hypotheticalItems.income = [];
  hypotheticalItems.expense = [];
};

// Calculate monthly amount from frequency
const calculateMonthlyAmount = (amountCents, frequency) => {
  const multipliers = {
    daily: 30,
    weekly: 52 / 12,
    biweekly: 26 / 12,
    bimonthly: 2,
    monthly: 1,
    quarterly: 1 / 3,
    yearly: 1 / 12,
  };
  return Math.round(amountCents * (multipliers[frequency] || 1));
};

// Add hypothetical income item
const addHypotheticalIncome = () => {
  const amount = parseFloat(newIncomeItem.amount);
  if (!newIncomeItem.description.trim() || isNaN(amount) || amount <= 0) return;
  
  const amountCents = Math.round(amount * 100);
  const monthlyAmount = calculateMonthlyAmount(amountCents, newIncomeItem.frequency);
  
  hypotheticalItems.income.push({
    id: `hypo-income-${hypotheticalIdCounter++}`,
    description: newIncomeItem.description.trim(),
    amount_cents: amountCents,
    frequency: newIncomeItem.frequency,
    monthly_amount: monthlyAmount,
    isHypothetical: true,
  });
  
  // Reset form
  newIncomeItem.description = '';
  newIncomeItem.amount = '';
  newIncomeItem.frequency = 'monthly';
  showAddIncomeForm.value = false;
};

// Add hypothetical expense item
const addHypotheticalExpense = () => {
  const amount = parseFloat(newExpenseItem.amount);
  if (!newExpenseItem.description.trim() || isNaN(amount) || amount <= 0) return;
  
  const amountCents = Math.round(amount * 100);
  const monthlyAmount = -calculateMonthlyAmount(amountCents, newExpenseItem.frequency);
  
  hypotheticalItems.expense.push({
    id: `hypo-expense-${hypotheticalIdCounter++}`,
    description: newExpenseItem.description.trim(),
    amount_cents: -amountCents,
    frequency: newExpenseItem.frequency,
    monthly_amount: monthlyAmount,
    isHypothetical: true,
  });
  
  // Reset form
  newExpenseItem.description = '';
  newExpenseItem.amount = '';
  newExpenseItem.frequency = 'monthly';
  showAddExpenseForm.value = false;
};

// Remove hypothetical item
const removeHypotheticalItem = (type, itemId) => {
  const index = hypotheticalItems[type].findIndex(item => item.id === itemId);
  if (index !== -1) {
    hypotheticalItems[type].splice(index, 1);
  }
};

// Calculate original totals (without adjustments) for comparison
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

// Calculate adjusted totals (including hypothetical items)
const adjustedTotals = computed(() => {
  // Real income items with adjustments
  let monthlyIncome = filteredIncomeItems.value.reduce((sum, item) => {
    return sum + getAdjustedAmount('income', item.id, item.monthly_amount);
  }, 0);
  
  // Add hypothetical income items
  monthlyIncome += hypotheticalItems.income.reduce((sum, item) => sum + item.monthly_amount, 0);
  
  // Real expense items with adjustments
  let monthlyExpenses = Math.abs(filteredExpenseItems.value.reduce((sum, item) => {
    return sum + getAdjustedAmount('expense', item.id, item.monthly_amount);
  }, 0));
  
  // Add hypothetical expense items
  monthlyExpenses += Math.abs(hypotheticalItems.expense.reduce((sum, item) => sum + item.monthly_amount, 0));
  
  // Autopay items with adjustments
  const monthlyAutopay = Math.abs(filteredAutopayItems.value.reduce((sum, item) => {
    return sum + getAdjustedAmount('autopay', item.id, item.monthly_amount);
  }, 0));
  
  return {
    monthly_income: monthlyIncome,
    monthly_expenses: monthlyExpenses,
    monthly_autopay: monthlyAutopay,
    net: monthlyIncome - monthlyExpenses - monthlyAutopay,
  };
});

// Calculate adjustment differences for display
const incomeAdjustmentDiff = computed(() => adjustedTotals.value.monthly_income - filteredTotals.value.monthly_income);
const expenseAdjustmentDiff = computed(() => adjustedTotals.value.monthly_expenses - filteredTotals.value.monthly_expenses);
const autopayAdjustmentDiff = computed(() => adjustedTotals.value.monthly_autopay - filteredTotals.value.monthly_autopay);
const netAdjustmentDiff = computed(() => adjustedTotals.value.net - filteredTotals.value.net);

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

// Frequency options for the form
const frequencyOptions = [
  { value: 'daily', label: 'Daily' },
  { value: 'weekly', label: 'Weekly' },
  { value: 'biweekly', label: 'Biweekly' },
  { value: 'bimonthly', label: 'Twice/Month' },
  { value: 'monthly', label: 'Monthly' },
  { value: 'quarterly', label: 'Quarterly' },
  { value: 'yearly', label: 'Yearly' },
];
</script>

<template>
  <div>
    <!-- Filter Bar -->
    <div class="mb-6 bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
      <div class="flex flex-col sm:flex-row sm:items-center gap-4">
        <div class="flex items-center gap-2">
          <label for="income-expense-account-filter" class="text-sm font-medium text-gray-700 dark:text-gray-300">Filter by Account:</label>
        </div>
        <select
          id="income-expense-account-filter"
          v-model="selectedAccountId"
          class="block w-full sm:w-64 py-2 px-3 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
        >
          <option value="all">All Accounts</option>
          <option v-for="account in accounts" :key="account.id" :value="account.id">
            {{ account.name }}
          </option>
        </select>
        <div v-if="selectedAccountId !== 'all'" class="text-sm text-gray-500 dark:text-gray-400">
          Showing items for: <span class="font-medium text-gray-700 dark:text-gray-300">{{ selectedAccountName }}</span>
        </div>
        
        <!-- Simulation Mode Toggle -->
        <div class="flex items-center gap-2 ml-auto">
          <label class="relative inline-flex items-center cursor-pointer">
            <input type="checkbox" v-model="simulationMode" class="sr-only peer">
            <div class="w-11 h-6 bg-gray-200 dark:bg-gray-600 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 dark:peer-focus:ring-indigo-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
            <span class="ml-2 text-sm font-medium text-gray-700 dark:text-gray-300">What-If Mode</span>
          </label>
          <button
            v-if="simulationMode && hasAdjustments"
            @click="resetAdjustments"
            class="px-3 py-1.5 text-xs font-medium text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-600 hover:bg-gray-200 dark:hover:bg-gray-500 rounded-md transition-colors"
          >
            Reset All
          </button>
        </div>
      </div>
      
      <!-- Simulation Mode Hint -->
      <div v-if="simulationMode" class="mt-3 p-3 bg-indigo-50 dark:bg-indigo-900/30 rounded-lg">
        <p class="text-sm text-indigo-700 dark:text-indigo-300">
          <strong>What-If Mode:</strong> Click on any amount to adjust it and see how it affects your monthly totals. You can also add hypothetical income or expenses. Changes are for simulation only and won't be saved.
        </p>
      </div>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
      <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-5">
        <h3 class="text-sm font-medium text-green-700 dark:text-green-400 mb-2">Monthly Income</h3>
        <div class="text-2xl font-bold text-green-600 dark:text-green-400">
          {{ formatCurrency(adjustedTotals.monthly_income) }}
        </div>
        <div v-if="incomeAdjustmentDiff !== 0" class="text-xs mt-1" :class="incomeAdjustmentDiff > 0 ? 'text-green-500' : 'text-red-500'">
          {{ incomeAdjustmentDiff > 0 ? '+' : '' }}{{ formatCurrency(incomeAdjustmentDiff) }} from original
        </div>
      </div>

      <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-5">
        <h3 class="text-sm font-medium text-red-700 dark:text-red-400 mb-2">Monthly Expenses</h3>
        <div class="text-2xl font-bold text-red-600 dark:text-red-400">
          {{ formatCurrency(adjustedTotals.monthly_expenses) }}
        </div>
        <div v-if="expenseAdjustmentDiff !== 0" class="text-xs mt-1" :class="expenseAdjustmentDiff < 0 ? 'text-green-500' : 'text-red-500'">
          {{ expenseAdjustmentDiff > 0 ? '+' : '' }}{{ formatCurrency(expenseAdjustmentDiff) }} from original
        </div>
      </div>

      <div class="bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 rounded-lg p-5">
        <h3 class="text-sm font-medium text-orange-700 dark:text-orange-400 mb-2">Autopay Payments</h3>
        <div class="text-2xl font-bold text-orange-600 dark:text-orange-400">
          {{ formatCurrency(adjustedTotals.monthly_autopay) }}
        </div>
        <div v-if="autopayAdjustmentDiff !== 0" class="text-xs mt-1" :class="autopayAdjustmentDiff < 0 ? 'text-green-500' : 'text-red-500'">
          {{ autopayAdjustmentDiff > 0 ? '+' : '' }}{{ formatCurrency(autopayAdjustmentDiff) }} from original
        </div>
      </div>

      <div class="bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg p-5">
        <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">Net Monthly</h3>
        <div class="text-2xl font-bold" :class="adjustedTotals.net >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
          {{ formatCurrency(adjustedTotals.net) }}
        </div>
        <div v-if="netAdjustmentDiff !== 0" class="text-xs mt-1" :class="netAdjustmentDiff > 0 ? 'text-green-500' : 'text-red-500'">
          {{ netAdjustmentDiff > 0 ? '+' : '' }}{{ formatCurrency(netAdjustmentDiff) }} from original
        </div>
      </div>
    </div>

    <!-- Side by Side Tables -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- Income Table -->
      <div class="bg-white dark:bg-gray-700 overflow-hidden border border-gray-200 dark:border-gray-600 rounded-lg">
        <div class="p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white flex items-center">
              <ArrowTrendingUpIcon class="w-5 h-5 mr-2 text-green-500" />
              Income
            </h3>
            <span class="text-sm text-gray-500 dark:text-gray-400">{{ filteredIncomeItems.length + hypotheticalItems.income.length }} items</span>
          </div>

          <div v-if="filteredIncomeItems.length > 0 || hypotheticalItems.income.length > 0" class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-600">
              <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Description
                  </th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Frequency
                  </th>
                  <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Monthly
                  </th>
                </tr>
              </thead>
              <tbody class="bg-white dark:bg-gray-700 divide-y divide-gray-200 dark:divide-gray-600">
                <!-- Real Income Items -->
                <tr v-for="item in filteredIncomeItems" :key="item.id" class="hover:bg-gray-50 dark:hover:bg-gray-600">
                  <td class="px-4 py-3 whitespace-nowrap">
                    <div class="text-sm font-medium text-gray-900 dark:text-white">{{ item.description }}</div>
                    <div v-if="item.account_name" class="text-xs text-gray-500 dark:text-gray-400">{{ item.account_name }}</div>
                  </td>
                  <td class="px-4 py-3 whitespace-nowrap">
                    <span class="px-2 py-1 text-xs font-medium bg-gray-100 dark:bg-gray-600 text-gray-700 dark:text-gray-300 rounded-full">
                      {{ formatFrequency(item.frequency) }}
                    </span>
                  </td>
                  <td class="px-4 py-3 whitespace-nowrap text-right">
                    <!-- Editable Amount -->
                    <div v-if="simulationMode" class="flex items-center justify-end gap-1">
                      <span class="text-gray-400">$</span>
                      <input
                        type="number"
                        :value="getAdjustedAmount('income', item.id, item.monthly_amount) / 100"
                        @change="updateAdjustment('income', item.id, $event)"
                        class="w-24 px-2 py-1 text-sm font-semibold text-green-600 dark:text-green-400 text-right border border-gray-300 dark:border-gray-500 dark:bg-gray-800 rounded focus:ring-indigo-500 focus:border-indigo-500"
                        step="0.01"
                      />
                      <button
                        v-if="hasItemAdjustment('income', item.id)"
                        @click="clearItemAdjustment('income', item.id)"
                        class="p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                        title="Reset to original"
                      >
                        <XMarkIcon class="w-4 h-4" />
                      </button>
                    </div>
                    <!-- Static Display -->
                    <div v-else>
                      <span class="text-sm font-semibold text-green-600 dark:text-green-400">
                        {{ formatCurrency(getAdjustedAmount('income', item.id, item.monthly_amount)) }}
                      </span>
                    </div>
                    <!-- Original value indicator -->
                    <div v-if="hasItemAdjustment('income', item.id)" class="text-xs text-gray-400 line-through">
                      {{ formatCurrency(item.monthly_amount) }}
                    </div>
                  </td>
                </tr>

                <!-- Hypothetical Income Items -->
                <tr v-for="item in hypotheticalItems.income" :key="item.id" class="bg-indigo-50 dark:bg-indigo-900/20 hover:bg-indigo-100 dark:hover:bg-indigo-900/30">
                  <td class="px-4 py-3 whitespace-nowrap">
                    <div class="flex items-center gap-2">
                      <div class="text-sm font-medium text-gray-900 dark:text-white">{{ item.description }}</div>
                      <span class="px-1.5 py-0.5 text-xs font-medium bg-indigo-100 dark:bg-indigo-800 text-indigo-700 dark:text-indigo-300 rounded">Hypothetical</span>
                    </div>
                  </td>
                  <td class="px-4 py-3 whitespace-nowrap">
                    <span class="px-2 py-1 text-xs font-medium bg-indigo-100 dark:bg-indigo-800 text-indigo-700 dark:text-indigo-300 rounded-full">
                      {{ formatFrequency(item.frequency) }}
                    </span>
                  </td>
                  <td class="px-4 py-3 whitespace-nowrap text-right">
                    <div class="flex items-center justify-end gap-2">
                      <span class="text-sm font-semibold text-green-600 dark:text-green-400">
                        {{ formatCurrency(item.monthly_amount) }}
                      </span>
                      <button
                        @click="removeHypotheticalItem('income', item.id)"
                        class="p-1 text-gray-400 hover:text-red-600 dark:hover:text-red-400"
                        title="Remove item"
                      >
                        <XMarkIcon class="w-4 h-4" />
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
              <tfoot class="bg-gray-50 dark:bg-gray-800">
                <tr>
                  <td colspan="2" class="px-4 py-3 text-sm font-semibold text-gray-900 dark:text-white">
                    Total Monthly Income
                  </td>
                  <td class="px-4 py-3 text-right text-sm font-bold text-green-600 dark:text-green-400">
                    {{ formatCurrency(adjustedTotals.monthly_income) }}
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>

          <div v-else class="text-center py-8 text-gray-500 dark:text-gray-400">
            <ArrowTrendingUpIcon class="w-12 h-12 mx-auto mb-3 text-gray-300 dark:text-gray-600" />
            <p>No recurring income found.</p>
            <Link
              :href="route('recurring-transactions.create', budget.id)"
              class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-500 text-sm font-medium mt-2 inline-block"
            >
              Add recurring income
            </Link>
          </div>

          <!-- Add Hypothetical Income Button -->
          <div v-if="simulationMode" class="mt-4 border-t border-gray-200 dark:border-gray-600 pt-4">
            <button
              v-if="!showAddIncomeForm"
              @click="showAddIncomeForm = true"
              class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-900/30 hover:bg-indigo-100 dark:hover:bg-indigo-900/50 rounded-md transition-colors"
            >
              <PlusIcon class="w-4 h-4" />
              Add Hypothetical Income
            </button>
            
            <!-- Add Income Form -->
            <div v-else class="bg-indigo-50 dark:bg-indigo-900/20 rounded-lg p-4 space-y-3">
              <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <input
                  v-model="newIncomeItem.description"
                  type="text"
                  placeholder="Description"
                  class="block w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                />
                <input
                  v-model="newIncomeItem.amount"
                  type="number"
                  placeholder="Amount"
                  step="0.01"
                  min="0"
                  class="block w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                />
                <select
                  v-model="newIncomeItem.frequency"
                  class="block w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                >
                  <option v-for="opt in frequencyOptions" :key="opt.value" :value="opt.value">
                    {{ opt.label }}
                  </option>
                </select>
              </div>
              <div class="flex gap-2">
                <button
                  @click="addHypotheticalIncome"
                  class="px-3 py-1.5 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-md transition-colors"
                >
                  Add
                </button>
                <button
                  @click="showAddIncomeForm = false"
                  class="px-3 py-1.5 text-sm font-medium text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-600 hover:bg-gray-200 dark:hover:bg-gray-500 rounded-md transition-colors"
                >
                  Cancel
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Expenses Table -->
      <div class="bg-white dark:bg-gray-700 overflow-hidden border border-gray-200 dark:border-gray-600 rounded-lg">
        <div class="p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white flex items-center">
              <ArrowTrendingDownIcon class="w-5 h-5 mr-2 text-red-500" />
              Expenses
            </h3>
            <span class="text-sm text-gray-500 dark:text-gray-400">{{ filteredExpenseItems.length + filteredAutopayItems.length + hypotheticalItems.expense.length }} items</span>
          </div>

          <div v-if="filteredExpenseItems.length > 0 || filteredAutopayItems.length > 0 || hypotheticalItems.expense.length > 0" class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-600">
              <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Description
                  </th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Frequency
                  </th>
                  <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Monthly
                  </th>
                </tr>
              </thead>
              <tbody class="bg-white dark:bg-gray-700 divide-y divide-gray-200 dark:divide-gray-600">
                <!-- Regular Recurring Expenses -->
                <tr v-for="item in filteredExpenseItems" :key="item.id" class="hover:bg-gray-50 dark:hover:bg-gray-600">
                  <td class="px-4 py-3 whitespace-nowrap">
                    <div class="text-sm font-medium text-gray-900 dark:text-white">{{ item.description }}</div>
                    <div v-if="item.account_name" class="text-xs text-gray-500 dark:text-gray-400">{{ item.account_name }}</div>
                  </td>
                  <td class="px-4 py-3 whitespace-nowrap">
                    <span class="px-2 py-1 text-xs font-medium bg-gray-100 dark:bg-gray-600 text-gray-700 dark:text-gray-300 rounded-full">
                      {{ formatFrequency(item.frequency) }}
                    </span>
                  </td>
                  <td class="px-4 py-3 whitespace-nowrap text-right">
                    <!-- Editable Amount -->
                    <div v-if="simulationMode" class="flex items-center justify-end gap-1">
                      <span class="text-gray-400">$</span>
                      <input
                        type="number"
                        :value="Math.abs(getAdjustedAmount('expense', item.id, item.monthly_amount)) / 100"
                        @change="updateExpenseAdjustment('expense', item.id, $event)"
                        class="w-24 px-2 py-1 text-sm font-semibold text-red-600 dark:text-red-400 text-right border border-gray-300 dark:border-gray-500 dark:bg-gray-800 rounded focus:ring-indigo-500 focus:border-indigo-500"
                        step="0.01"
                      />
                      <button
                        v-if="hasItemAdjustment('expense', item.id)"
                        @click="clearItemAdjustment('expense', item.id)"
                        class="p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                        title="Reset to original"
                      >
                        <XMarkIcon class="w-4 h-4" />
                      </button>
                    </div>
                    <!-- Static Display -->
                    <div v-else>
                      <span class="text-sm font-semibold text-red-600 dark:text-red-400">
                        {{ formatCurrency(Math.abs(getAdjustedAmount('expense', item.id, item.monthly_amount))) }}
                      </span>
                    </div>
                    <!-- Original value indicator -->
                    <div v-if="hasItemAdjustment('expense', item.id)" class="text-xs text-gray-400 line-through">
                      {{ formatCurrency(Math.abs(item.monthly_amount)) }}
                    </div>
                  </td>
                </tr>

                <!-- Hypothetical Expense Items -->
                <tr v-for="item in hypotheticalItems.expense" :key="item.id" class="bg-indigo-50 dark:bg-indigo-900/20 hover:bg-indigo-100 dark:hover:bg-indigo-900/30">
                  <td class="px-4 py-3 whitespace-nowrap">
                    <div class="flex items-center gap-2">
                      <div class="text-sm font-medium text-gray-900 dark:text-white">{{ item.description }}</div>
                      <span class="px-1.5 py-0.5 text-xs font-medium bg-indigo-100 dark:bg-indigo-800 text-indigo-700 dark:text-indigo-300 rounded">Hypothetical</span>
                    </div>
                  </td>
                  <td class="px-4 py-3 whitespace-nowrap">
                    <span class="px-2 py-1 text-xs font-medium bg-indigo-100 dark:bg-indigo-800 text-indigo-700 dark:text-indigo-300 rounded-full">
                      {{ formatFrequency(item.frequency) }}
                    </span>
                  </td>
                  <td class="px-4 py-3 whitespace-nowrap text-right">
                    <div class="flex items-center justify-end gap-2">
                      <span class="text-sm font-semibold text-red-600 dark:text-red-400">
                        {{ formatCurrency(Math.abs(item.monthly_amount)) }}
                      </span>
                      <button
                        @click="removeHypotheticalItem('expense', item.id)"
                        class="p-1 text-gray-400 hover:text-red-600 dark:hover:text-red-400"
                        title="Remove item"
                      >
                        <XMarkIcon class="w-4 h-4" />
                      </button>
                    </div>
                  </td>
                </tr>

                <!-- Autopay Section Header -->
                <tr v-if="filteredAutopayItems.length > 0" class="bg-orange-50 dark:bg-orange-900/20">
                  <td colspan="3" class="px-4 py-2">
                    <div class="flex items-center text-sm font-medium text-orange-700 dark:text-orange-400">
                      <CreditCardIcon class="w-4 h-4 mr-2" />
                      Credit Card Autopay Payments
                    </div>
                  </td>
                </tr>

                <!-- Autopay Items -->
                <tr v-for="item in filteredAutopayItems" :key="'autopay-' + item.id" class="hover:bg-orange-50/50 dark:hover:bg-orange-900/10">
                  <td class="px-4 py-3 whitespace-nowrap">
                    <div class="text-sm font-medium text-gray-900 dark:text-white">{{ item.name }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">from {{ item.source_account_name }}</div>
                  </td>
                  <td class="px-4 py-3 whitespace-nowrap">
                    <span class="px-2 py-1 text-xs font-medium bg-orange-100 dark:bg-orange-800 text-orange-700 dark:text-orange-300 rounded-full">
                      Monthly
                    </span>
                  </td>
                  <td class="px-4 py-3 whitespace-nowrap text-right">
                    <!-- Editable Amount -->
                    <div v-if="simulationMode" class="flex items-center justify-end gap-1">
                      <span class="text-gray-400">$</span>
                      <input
                        type="number"
                        :value="Math.abs(getAdjustedAmount('autopay', item.id, item.monthly_amount)) / 100"
                        @change="updateExpenseAdjustment('autopay', item.id, $event)"
                        class="w-24 px-2 py-1 text-sm font-semibold text-orange-600 dark:text-orange-400 text-right border border-gray-300 dark:border-gray-500 dark:bg-gray-800 rounded focus:ring-indigo-500 focus:border-indigo-500"
                        step="0.01"
                      />
                      <button
                        v-if="hasItemAdjustment('autopay', item.id)"
                        @click="clearItemAdjustment('autopay', item.id)"
                        class="p-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                        title="Reset to original"
                      >
                        <XMarkIcon class="w-4 h-4" />
                      </button>
                    </div>
                    <!-- Static Display -->
                    <div v-else>
                      <span class="text-sm font-semibold text-orange-600 dark:text-orange-400">
                        {{ formatCurrency(Math.abs(getAdjustedAmount('autopay', item.id, item.monthly_amount))) }}
                      </span>
                    </div>
                    <!-- Original value indicator -->
                    <div v-if="hasItemAdjustment('autopay', item.id)" class="text-xs text-gray-400 line-through">
                      {{ formatCurrency(Math.abs(item.monthly_amount)) }}
                    </div>
                  </td>
                </tr>
              </tbody>
              <tfoot class="bg-gray-50 dark:bg-gray-800">
                <tr v-if="filteredExpenseItems.length > 0 || hypotheticalItems.expense.length > 0">
                  <td colspan="2" class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                    Recurring Expenses
                  </td>
                  <td class="px-4 py-2 text-right text-sm font-semibold text-red-600 dark:text-red-400">
                    {{ formatCurrency(adjustedTotals.monthly_expenses) }}
                  </td>
                </tr>
                <tr v-if="filteredAutopayItems.length > 0">
                  <td colspan="2" class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                    Autopay Payments
                  </td>
                  <td class="px-4 py-2 text-right text-sm font-semibold text-orange-600 dark:text-orange-400">
                    {{ formatCurrency(adjustedTotals.monthly_autopay) }}
                  </td>
                </tr>
                <tr class="border-t-2 border-gray-300 dark:border-gray-500">
                  <td colspan="2" class="px-4 py-3 text-sm font-semibold text-gray-900 dark:text-white">
                    Total Monthly Expenses
                  </td>
                  <td class="px-4 py-3 text-right text-sm font-bold text-red-600 dark:text-red-400">
                    {{ formatCurrency(adjustedTotals.monthly_expenses + adjustedTotals.monthly_autopay) }}
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>

          <div v-else class="text-center py-8 text-gray-500 dark:text-gray-400">
            <ArrowTrendingDownIcon class="w-12 h-12 mx-auto mb-3 text-gray-300 dark:text-gray-600" />
            <p>No recurring expenses found.</p>
            <Link
              :href="route('recurring-transactions.create', budget.id)"
              class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-500 text-sm font-medium mt-2 inline-block"
            >
              Add recurring expense
            </Link>
          </div>

          <!-- Add Hypothetical Expense Button -->
          <div v-if="simulationMode" class="mt-4 border-t border-gray-200 dark:border-gray-600 pt-4">
            <button
              v-if="!showAddExpenseForm"
              @click="showAddExpenseForm = true"
              class="flex items-center gap-2 px-3 py-2 text-sm font-medium text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-900/30 hover:bg-indigo-100 dark:hover:bg-indigo-900/50 rounded-md transition-colors"
            >
              <PlusIcon class="w-4 h-4" />
              Add Hypothetical Expense
            </button>
            
            <!-- Add Expense Form -->
            <div v-else class="bg-indigo-50 dark:bg-indigo-900/20 rounded-lg p-4 space-y-3">
              <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <input
                  v-model="newExpenseItem.description"
                  type="text"
                  placeholder="Description"
                  class="block w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                />
                <input
                  v-model="newExpenseItem.amount"
                  type="number"
                  placeholder="Amount"
                  step="0.01"
                  min="0"
                  class="block w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                />
                <select
                  v-model="newExpenseItem.frequency"
                  class="block w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                >
                  <option v-for="opt in frequencyOptions" :key="opt.value" :value="opt.value">
                    {{ opt.label }}
                  </option>
                </select>
              </div>
              <div class="flex gap-2">
                <button
                  @click="addHypotheticalExpense"
                  class="px-3 py-1.5 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-md transition-colors"
                >
                  Add
                </button>
                <button
                  @click="showAddExpenseForm = false"
                  class="px-3 py-1.5 text-sm font-medium text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-600 hover:bg-gray-200 dark:hover:bg-gray-500 rounded-md transition-colors"
                >
                  Cancel
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Net Summary -->
    <div class="mt-6 bg-white dark:bg-gray-700 overflow-hidden border border-gray-200 dark:border-gray-600 rounded-lg">
      <div class="p-6">
        <div class="flex items-center justify-between">
          <div>
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Monthly Summary</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
              <span v-if="hasAdjustments" class="text-indigo-600 dark:text-indigo-400 font-medium">
                Simulated values - 
              </span>
              Based on your recurring transactions and autopay settings
              <span v-if="selectedAccountId !== 'all'" class="text-indigo-600 dark:text-indigo-400">
                (filtered by {{ selectedAccountName }})
              </span>
            </p>
          </div>
          <div class="text-right">
            <div class="text-sm text-gray-500 dark:text-gray-400">Net Monthly Cash Flow</div>
            <div
              class="text-3xl font-bold"
              :class="adjustedTotals.net >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
            >
              {{ formatCurrency(adjustedTotals.net) }}
            </div>
            <div class="text-sm mt-1" :class="adjustedTotals.net >= 0 ? 'text-green-500' : 'text-red-500'">
              {{ adjustedTotals.net >= 0 ? 'Surplus' : 'Deficit' }} per month
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
