<template>
  <Head :title="`${account.name} - Account Projections`" />

  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ account.name }} - Account Projections</h2>
        <div class="flex space-x-2">
          <Link 
            :href="route('budget.account.balance-projection', { budget: budget.id, account: account.id })" 
            class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700"
          >
            View Detailed Balance Projection
          </Link>
          <Link 
            :href="route('budgets.show', budget.id)" 
            class="inline-flex items-center px-4 py-2 bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-300"
          >
            Back to Budget
          </Link>
        </div>
      </div>
    </template>

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Projection Controls -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
          <div class="p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Projection Settings</h3>
            
            <form @submit.prevent="updateProjections" class="flex flex-wrap gap-4 items-end">
              <div>
                <InputLabel for="months" value="Months to Project" />
                <select
                  id="months"
                  v-model="form.months"
                  class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                  required
                >
                  <option value="1">1 month</option>
                  <option value="3">3 months</option>
                  <option value="6">6 months</option>
                  <option value="12">12 months</option>
                  <option value="24">24 months</option>
                </select>
              </div>
              
              <div>
                <PrimaryButton type="submit" :disabled="processing">
                  Update Projections
                </PrimaryButton>
              </div>
            </form>
          </div>
        </div>
        
        <!-- Account Balance Summary -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
          <div class="p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Account Balance Summary</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
              <div>
                <h4 class="text-sm uppercase text-gray-500 mb-1">Current Balance</h4>
                <p class="text-2xl font-bold" :class="account.current_balance_in_cents >= 0 ? 'text-green-600' : 'text-red-600'">
                  ${{ formatCurrency(account.current_balance_in_cents) }}
                </p>
              </div>
              
              <div>
                <h4 class="text-sm uppercase text-gray-500 mb-1">Projected Income</h4>
                <p class="text-2xl font-bold text-green-600">
                  ${{ formatCurrency(totalIncome) }}
                </p>
              </div>
              
              <div>
                <h4 class="text-sm uppercase text-gray-500 mb-1">Projected Expenses</h4>
                <p class="text-2xl font-bold text-red-600">
                  ${{ formatCurrency(totalExpenses) }}
                </p>
              </div>
              
              <div>
                <h4 class="text-sm uppercase text-gray-500 mb-1">Net Change</h4>
                <p class="text-2xl font-bold" :class="netChange >= 0 ? 'text-green-600' : 'text-red-600'">
                  ${{ formatCurrency(netChange) }}
                </p>
              </div>
              
              <div>
                <h4 class="text-sm uppercase text-gray-500 mb-1">Ending Balance</h4>
                <p class="text-2xl font-bold" :class="projectedEndingBalance >= 0 ? 'text-green-600' : 'text-red-600'">
                  ${{ formatCurrency(projectedEndingBalance) }}
                </p>
                <p class="text-xs text-gray-500">as of {{ endDate }}</p>
              </div>
              
              <div>
                <h4 class="text-sm uppercase text-gray-500 mb-1">Growth Rate</h4>
                <p class="text-2xl font-bold" :class="growthRate >= 0 ? 'text-green-600' : 'text-red-600'">
                  {{ growthRate >= 0 ? '+' : '' }}{{ growthRate.toFixed(1) }}%
                </p>
                <p class="text-xs text-gray-500">over {{ form.months }} {{ form.months > 1 ? 'months' : 'month' }}</p>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Daily Balance Chart -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
          <div class="p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Daily Balance Projection</h3>
            
            <!-- Balance Chart -->
            <div class="mb-6" style="height: 400px;">
              <BalanceChart 
                :balance-data="balanceProjectionData" 
                :height="350"
                :show-positive-negative="true"
              />
            </div>
            
            <!-- Balance table -->
            <div v-if="props.balanceProjection && props.balanceProjection.days" class="overflow-x-auto">
              <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                  <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Income</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Expenses</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                  </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                  <tr v-for="day in props.balanceProjection.days" :key="day.date">
                    <td class="px-6 py-4 whitespace-nowrap">
                      <div class="text-sm font-medium text-gray-900">{{ formatDateShort(day.date) }}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                      <div v-if="day.income > 0" class="text-sm text-green-600">
                        ${{ formatCurrency(day.income) }}
                      </div>
                      <div v-else class="text-sm text-gray-400">-</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                      <div v-if="day.expense > 0" class="text-sm text-red-600">
                        ${{ formatCurrency(day.expense) }}
                      </div>
                      <div v-else class="text-sm text-gray-400">-</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                      <div class="text-sm font-medium" :class="day.balance >= 0 ? 'text-green-600' : 'text-red-600'">
                        ${{ formatCurrency(day.balance) }}
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        
        <!-- Transactions List -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Projected Transactions</h3>
            
            <div v-if="!props.projectedTransactions || props.projectedTransactions.length === 0" class="text-center py-10 text-gray-500">
              <p>No projected transactions found for this account.</p>
            </div>
            
            <div v-else>
              <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                  <thead class="bg-gray-50">
                    <tr>
                      <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                      <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                      <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                      <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                      <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    </tr>
                  </thead>
                  <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="transaction in sortedTransactions" :key="transaction.id || transaction.date" class="hover:bg-gray-50">
                      <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-900">{{ formatDate(transaction.date) }}</div>
                      </td>
                      <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium text-gray-900">{{ transaction.description }}</div>
                      </td>
                      <td class="px-6 py-4 whitespace-nowrap">
                        <div v-if="transaction.category" class="text-sm text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full inline-block">
                          {{ transaction.category }}
                        </div>
                        <div v-else class="text-sm text-gray-400">-</div>
                      </td>
                      <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm font-medium" :class="transaction.amount_in_cents >= 0 ? 'text-green-600' : 'text-red-600'">
                          ${{ formatCurrency(transaction.amount_in_cents) }}
                        </div>
                      </td>
                      <td class="px-6 py-4 whitespace-nowrap">
                        <div v-if="transaction.is_projected" class="text-xs px-2 py-1 rounded-full bg-blue-100 text-blue-800 inline-block">
                          Projected
                        </div>
                        <div v-else class="text-xs px-2 py-1 rounded-full bg-green-100 text-green-800 inline-block">
                          Confirmed
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
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import BalanceChart from '@/Components/BalanceChart.vue';
import { formatCurrency } from '@/utils/format.js';

// Define props
const props = defineProps({
  budget: Object,
  account: Object,
  projectedTransactions: Array,
  balanceProjection: Object,
  monthsAhead: Number,
});

// Form state
const form = ref({
  months: props.monthsAhead || 3,
});
const processing = ref(false);

// Computed values
const sortedTransactions = computed(() => {
  if (!props.projectedTransactions) return [];
  return [...props.projectedTransactions].sort((a, b) => {
    return new Date(a.date) - new Date(b.date);
  });
});

const totalIncome = computed(() => {
  if (!props.projectedTransactions) return 0;
  return props.projectedTransactions
    .filter(t => t.amount_in_cents > 0)
    .reduce((sum, t) => sum + t.amount_in_cents, 0);
});

const totalExpenses = computed(() => {
  if (!props.projectedTransactions) return 0;
  return Math.abs(props.projectedTransactions
    .filter(t => t.amount_in_cents < 0)
    .reduce((sum, t) => sum + t.amount_in_cents, 0));
});

const netChange = computed(() => {
  return totalIncome.value - totalExpenses.value;
});

const projectedEndingBalance = computed(() => {
  if (!props.balanceProjection || !props.balanceProjection.days || props.balanceProjection.days.length === 0) {
    return props.account.current_balance_in_cents;
  }
  
  const lastDay = props.balanceProjection.days[props.balanceProjection.days.length - 1];
  return lastDay.balance;
});

const growthRate = computed(() => {
  const startingBalance = props.account.current_balance_in_cents;
  if (startingBalance === 0) return 0;
  
  const change = projectedEndingBalance.value - startingBalance;
  return (change / Math.abs(startingBalance)) * 100;
});

const endDate = computed(() => {
  if (!props.balanceProjection || !props.balanceProjection.days || props.balanceProjection.days.length === 0) {
    return 'N/A';
  }
  
  const lastDay = props.balanceProjection.days[props.balanceProjection.days.length - 1];
  return formatDate(lastDay.date);
});

// Format helpers
const formatDate = (dateString) => {
  if (!dateString) return 'N/A';
  
  try {
    const date = new Date(dateString);
    return date.toLocaleDateString(undefined, { 
      year: 'numeric', 
      month: 'long', 
      day: 'numeric' 
    });
  } catch (e) {
    return dateString;
  }
};

const formatDateShort = (dateString) => {
  if (!dateString) return 'N/A';
  
  try {
    const date = new Date(dateString);
    return date.toLocaleDateString(undefined, { 
      month: 'short', 
      day: 'numeric' 
    });
  } catch (e) {
    return dateString;
  }
};

// Prepare data for the balance chart
const balanceProjectionData = computed(() => {
  if (!props.balanceProjection || !props.balanceProjection.days) {
    return [];
  }
  
  return props.balanceProjection.days.map(day => ({
    date: day.date,
    balance: parseInt(day.balance)
  }));
});

// Update projections
const updateProjections = () => {
  processing.value = true;
  router.get(route('budget.account.projections', { budget: props.budget.id, account: props.account.id }), {
    months: form.value.months,
  }, {
    onFinish: () => {
      processing.value = false;
    }
  });
};
</script> 