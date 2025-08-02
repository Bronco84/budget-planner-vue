<template>
  <Head :title="`${account.name} - Balance Projection`" />

  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ account.name }} - Balance Projection</h2>
        <div class="flex space-x-2">
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
              <div class="w-full md:w-auto">
                <InputLabel for="months" value="Projection Time Range" />
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
                  <option value="36">36 months</option>
                  <option value="60">5 years</option>
                </select>
              </div>

              <div class="w-full md:w-auto">
                <InputLabel for="scenario" value="Scenario" />
                <select
                  id="scenario"
                  v-model="form.scenario"
                  class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                  required
                >
                  <option value="default">Default</option>
                  <option value="optimistic">Optimistic (+10%)</option>
                  <option value="pessimistic">Pessimistic (-10%)</option>
                </select>
              </div>

              <div class="w-full md:w-auto">
                <InputLabel for="groupBy" value="Group By" />
                <select
                  id="groupBy"
                  v-model="form.groupBy"
                  class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
                  required
                >
                  <option value="day">Daily</option>
                  <option value="week">Weekly</option>
                  <option value="month">Monthly</option>
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
                <p class="text-2xl font-bold" :class="account.current_balance_cents >= 0 ? 'text-green-600' : 'text-red-600'">
                  {{ formatCurrency(account.current_balance_cents) }}
                </p>
              </div>

              <div>
                <h4 class="text-sm uppercase text-gray-500 mb-1">Projected Income</h4>
                <p class="text-2xl font-bold text-green-600">
                  {{ formatCurrency(totalIncome) }}
                </p>
              </div>

              <div>
                <h4 class="text-sm uppercase text-gray-500 mb-1">Projected Expenses</h4>
                <p class="text-2xl font-bold text-red-600">
                  {{ formatCurrency(totalExpenses) }}
                </p>
              </div>

              <div>
                <h4 class="text-sm uppercase text-gray-500 mb-1">Net Change</h4>
                <p class="text-2xl font-bold" :class="netChange >= 0 ? 'text-green-600' : 'text-red-600'">
                  {{ formatCurrency(netChange) }}
                </p>
              </div>

              <div>
                <h4 class="text-sm uppercase text-gray-500 mb-1">Ending Balance</h4>
                <p class="text-2xl font-bold" :class="projectedEndingBalance >= 0 ? 'text-green-600' : 'text-red-600'">
                  {{ formatCurrency(projectedEndingBalance) }}
                </p>
                <p class="text-xs text-gray-500">as of {{ endDate }}</p>
              </div>

              <div>
                <h4 class="text-sm uppercase text-gray-500 mb-1">Monthly Growth Rate</h4>
                <p class="text-2xl font-bold" :class="averageMonthlyGrowth >= 0 ? 'text-green-600' : 'text-red-600'">
                  {{ averageMonthlyGrowth >= 0 ? '+' : '' }}{{ averageMonthlyGrowth.toFixed(1) }}%
                </p>
                <p class="text-xs text-gray-500">monthly average</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Balance Projection Chart -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
          <div class="p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Balance Projection Chart</h3>

            <div style="height: 500px;">
              <BalanceChart
                :balance-data="balanceProjectionData"
                :height="450"
                :show-positive-negative="true"
              />
            </div>

            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="p-4 bg-gray-50 rounded-lg">
                <h4 class="font-medium text-gray-700 mb-2">Key Insights</h4>
                <ul class="text-sm space-y-2">
                  <li v-if="lowestBalancePoint" class="flex justify-between">
                    <span>Lowest balance:</span>
                    <span :class="lowestBalancePoint.balance < 0 ? 'text-red-600 font-medium' : ''">
                      {{ formatCurrency(lowestBalancePoint.balance) }} on {{ formatDate(lowestBalancePoint.date) }}
                    </span>
                  </li>
                  <li v-if="highestBalancePoint" class="flex justify-between">
                    <span>Highest balance:</span>
                    <span class="text-green-600 font-medium">
                      {{ formatCurrency(highestBalancePoint.balance) }} on {{ formatDate(highestBalancePoint.date) }}
                    </span>
                  </li>
                  <li class="flex justify-between">
                    <span>Volatility:</span>
                    <span>{{ (balanceVolatility * 100).toFixed(1) }}%</span>
                  </li>
                </ul>
              </div>

              <div class="p-4 bg-gray-50 rounded-lg">
                <h4 class="font-medium text-gray-700 mb-2">Critical Points</h4>
                <ul class="text-sm space-y-2">
                  <li v-if="daysToNegative !== null" class="flex justify-between">
                    <span>Days until negative balance:</span>
                    <span :class="daysToNegative < 30 ? 'text-red-600 font-medium' : ''">
                      {{ daysToNegative }} days
                    </span>
                  </li>
                  <li v-else-if="projectedEndingBalance >= 0" class="flex justify-between">
                    <span>Balance remains positive</span>
                    <span class="text-green-600 font-medium">
                      Throughout projection period
                    </span>
                  </li>
                  <li v-if="timeToDoubleBalance !== null" class="flex justify-between">
                    <span>Time to double current balance:</span>
                    <span class="text-green-600 font-medium">
                      {{ timeToDoubleBalance }} days
                    </span>
                  </li>
                </ul>
              </div>
            </div>
          </div>
        </div>

        <!-- Monthly Projection Table -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
          <div class="p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Monthly Projection</h3>

            <div class="overflow-x-auto">
              <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                  <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Month</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Income</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Expenses</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Net</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">End Balance</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Monthly Growth</th>
                  </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                  <tr v-for="month in monthlyData" :key="month.month" class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                      <div class="text-sm font-medium text-gray-900">{{ month.monthLabel }}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right">
                      <div class="text-sm text-green-600">
                        {{ formatCurrency(month.income) }}
                      </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right">
                      <div class="text-sm text-red-600">
                        {{ formatCurrency(month.expenses) }}
                      </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right">
                      <div class="text-sm font-medium" :class="month.net >= 0 ? 'text-green-600' : 'text-red-600'">
                        {{ formatCurrency(month.net) }}
                      </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right">
                      <div class="text-sm font-medium" :class="month.endBalance >= 0 ? 'text-green-600' : 'text-red-600'">
                        {{ formatCurrency(month.endBalance) }}
                      </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right">
                      <div class="text-sm font-medium" :class="month.growthRate >= 0 ? 'text-green-600' : 'text-red-600'">
                        {{ month.growthRate >= 0 ? '+' : '' }}{{ month.growthRate.toFixed(1) }}%
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
  scenario: 'default',
  groupBy: 'day',
});
const processing = ref(false);

// Computed values for transactions
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

// Balance projection data
const balanceProjectionData = computed(() => {
  if (!props.balanceProjection || !props.balanceProjection.days) {
    return [];
  }

  let data = [...props.balanceProjection.days].map(day => ({
    date: day.date,
    balance: parseInt(day.balance)
  }));

  // Apply scenario adjustments
  if (form.value.scenario === 'optimistic') {
    // Optimistic: Increase income by 10%, decrease expenses by 10%
    const startingBalance = props.account.current_balance_cents;
    let runningBalance = startingBalance;

    data = data.map((day, index) => {
      if (index === 0) return day;

      const prevDay = data[index - 1];
      const dayChange = day.balance - prevDay.balance;

      // For income (positive change), increase by 10%
      // For expenses (negative change), decrease by 10%
      const adjustedChange = dayChange * (dayChange >= 0 ? 1.1 : 0.9);
      runningBalance += adjustedChange;

      return {
        date: day.date,
        balance: Math.round(runningBalance)
      };
    });
  } else if (form.value.scenario === 'pessimistic') {
    // Pessimistic: Decrease income by 10%, increase expenses by 10%
    const startingBalance = props.account.current_balance_cents;
    let runningBalance = startingBalance;

    data = data.map((day, index) => {
      if (index === 0) return day;

      const prevDay = data[index - 1];
      const dayChange = day.balance - prevDay.balance;

      // For income (positive change), decrease by 10%
      // For expenses (negative change), increase by 10%
      const adjustedChange = dayChange * (dayChange >= 0 ? 0.9 : 1.1);
      runningBalance += adjustedChange;

      return {
        date: day.date,
        balance: Math.round(runningBalance)
      };
    });
  }

  // Apply grouping if not daily
  if (form.value.groupBy === 'week') {
    // Group by week
    const weeklyData = [];
    let currentWeek = null;
    let lastDate = null;

    data.forEach(day => {
      const date = new Date(day.date);
      const weekNum = getWeekNumber(date);

      if (currentWeek !== weekNum) {
        if (currentWeek !== null) {
          weeklyData.push({
            date: lastDate,
            balance: day.balance
          });
        }
        currentWeek = weekNum;
      }

      lastDate = day.date;
    });

    // Add last week
    if (data.length > 0) {
      weeklyData.push({
        date: data[data.length - 1].date,
        balance: data[data.length - 1].balance
      });
    }

    return weeklyData;
  } else if (form.value.groupBy === 'month') {
    // Group by month
    const monthlyData = [];
    let currentMonth = null;

    data.forEach(day => {
      const date = new Date(day.date);
      const monthKey = `${date.getFullYear()}-${date.getMonth()}`;

      if (currentMonth !== monthKey) {
        monthlyData.push({
          date: day.date,
          balance: day.balance
        });
        currentMonth = monthKey;
      }
    });

    // Ensure we have the last day of the last month
    if (data.length > 0) {
      const lastDay = data[data.length - 1];
      monthlyData.push({
        date: lastDay.date,
        balance: lastDay.balance
      });
    }

    return monthlyData;
  }

  return data;
});

// Helper to get week number
function getWeekNumber(date) {
  const d = new Date(date);
  d.setHours(0, 0, 0, 0);
  d.setDate(d.getDate() + 4 - (d.getDay() || 7));
  const yearStart = new Date(d.getFullYear(), 0, 1);
  return Math.ceil((((d - yearStart) / 86400000) + 1) / 7);
}

// Balance analysis
const projectedEndingBalance = computed(() => {
  if (!balanceProjectionData.value || balanceProjectionData.value.length === 0) {
    return props.account.current_balance_cents;
  }

  const lastDay = balanceProjectionData.value[balanceProjectionData.value.length - 1];
  return lastDay.balance;
});

const endDate = computed(() => {
  if (!balanceProjectionData.value || balanceProjectionData.value.length === 0) {
    return 'N/A';
  }

  const lastDay = balanceProjectionData.value[balanceProjectionData.value.length - 1];
  return formatDate(lastDay.date);
});

const monthlyData = computed(() => {
  if (!props.balanceProjection || !props.balanceProjection.days) {
    return [];
  }

  const days = props.balanceProjection.days;
  const result = [];

  // Group transactions by month
  const transactionsByMonth = {};

  if (props.projectedTransactions) {
    props.projectedTransactions.forEach(transaction => {
      const date = new Date(transaction.date);
      const monthKey = `${date.getFullYear()}-${date.getMonth()}`;

      if (!transactionsByMonth[monthKey]) {
        transactionsByMonth[monthKey] = {
          month: monthKey,
          monthLabel: formatMonthYear(date),
          income: 0,
          expenses: 0,
          net: 0,
          startBalance: 0,
          endBalance: 0,
          growthRate: 0
        };
      }

      if (transaction.amount_in_cents > 0) {
        transactionsByMonth[monthKey].income += transaction.amount_in_cents;
      } else {
        transactionsByMonth[monthKey].expenses += Math.abs(transaction.amount_in_cents);
      }

      transactionsByMonth[monthKey].net += transaction.amount_in_cents;
    });
  }

  // Find the first and last transaction of each month to get start/end balances
  const daysByMonth = {};
  days.forEach(day => {
    const date = new Date(day.date);
    const monthKey = `${date.getFullYear()}-${date.getMonth()}`;

    if (!daysByMonth[monthKey]) {
      daysByMonth[monthKey] = [];
    }

    daysByMonth[monthKey].push({
      date: day.date,
      balance: parseInt(day.balance)
    });
  });

  // Calculate start and end balances for each month
  Object.keys(daysByMonth).sort().forEach(monthKey => {
    const monthDays = daysByMonth[monthKey];
    if (monthDays.length === 0) return;

    const startBalance = monthDays[0].balance;
    const endBalance = monthDays[monthDays.length - 1].balance;

    if (!transactionsByMonth[monthKey]) {
      // Create an entry if no transactions for this month
      transactionsByMonth[monthKey] = {
        month: monthKey,
        monthLabel: formatMonthYear(new Date(monthDays[0].date)),
        income: 0,
        expenses: 0,
        net: 0,
        startBalance: 0,
        endBalance: 0,
        growthRate: 0
      };
    }

    transactionsByMonth[monthKey].startBalance = startBalance;
    transactionsByMonth[monthKey].endBalance = endBalance;

    // Calculate monthly growth rate
    if (startBalance !== 0) {
      transactionsByMonth[monthKey].growthRate = ((endBalance - startBalance) / Math.abs(startBalance)) * 100;
    }
  });

  // Convert to array and sort by month
  return Object.values(transactionsByMonth).sort((a, b) => a.month.localeCompare(b.month));
});

const averageMonthlyGrowth = computed(() => {
  if (monthlyData.value.length === 0) return 0;

  const totalGrowth = monthlyData.value.reduce((sum, month) => sum + month.growthRate, 0);
  return totalGrowth / monthlyData.value.length;
});

// Insights and analysis
const lowestBalancePoint = computed(() => {
  if (!balanceProjectionData.value || balanceProjectionData.value.length === 0) {
    return null;
  }

  return balanceProjectionData.value.reduce((lowest, current) => {
    return (current.balance < lowest.balance) ? current : lowest;
  }, balanceProjectionData.value[0]);
});

const highestBalancePoint = computed(() => {
  if (!balanceProjectionData.value || balanceProjectionData.value.length === 0) {
    return null;
  }

  return balanceProjectionData.value.reduce((highest, current) => {
    return (current.balance > highest.balance) ? current : highest;
  }, balanceProjectionData.value[0]);
});

const balanceVolatility = computed(() => {
  if (!balanceProjectionData.value || balanceProjectionData.value.length <= 1) {
    return 0;
  }

  // Calculate standard deviation of daily changes
  const changes = [];
  for (let i = 1; i < balanceProjectionData.value.length; i++) {
    const prev = balanceProjectionData.value[i-1].balance;
    const curr = balanceProjectionData.value[i].balance;

    if (prev !== 0) {
      changes.push(Math.abs((curr - prev) / prev));
    }
  }

  if (changes.length === 0) return 0;

  const mean = changes.reduce((sum, val) => sum + val, 0) / changes.length;
  const squaredDiffs = changes.map(val => Math.pow(val - mean, 2));
  const variance = squaredDiffs.reduce((sum, val) => sum + val, 0) / changes.length;

  return Math.sqrt(variance);
});

const daysToNegative = computed(() => {
  if (!balanceProjectionData.value || balanceProjectionData.value.length === 0) {
    return null;
  }

  // If starting balance is already negative
  if (balanceProjectionData.value[0].balance < 0) {
    return 0;
  }

  // If ending balance is positive, and we never go negative in between
  const goesNegative = balanceProjectionData.value.some(day => day.balance < 0);
  if (!goesNegative) {
    return null; // Never goes negative
  }

  // Find first day with negative balance
  for (let i = 1; i < balanceProjectionData.value.length; i++) {
    if (balanceProjectionData.value[i].balance < 0) {
      const startDate = new Date(balanceProjectionData.value[0].date);
      const negativeDate = new Date(balanceProjectionData.value[i].date);
      const diffTime = Math.abs(negativeDate - startDate);
      return Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    }
  }

  return null;
});

const timeToDoubleBalance = computed(() => {
  if (!balanceProjectionData.value || balanceProjectionData.value.length === 0 || balanceProjectionData.value[0].balance <= 0) {
    return null;
  }

  const startBalance = balanceProjectionData.value[0].balance;
  const targetBalance = startBalance * 2;

  // Find first day with double the starting balance
  for (let i = 1; i < balanceProjectionData.value.length; i++) {
    if (balanceProjectionData.value[i].balance >= targetBalance) {
      const startDate = new Date(balanceProjectionData.value[0].date);
      const doubleDate = new Date(balanceProjectionData.value[i].date);
      const diffTime = Math.abs(doubleDate - startDate);
      return Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    }
  }

  return null; // Never doubles within projection period
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

const formatMonthYear = (dateObj) => {
  if (!dateObj) return 'N/A';

  try {
    return dateObj.toLocaleDateString(undefined, {
      year: 'numeric',
      month: 'long'
    });
  } catch (e) {
    return 'Invalid Date';
  }
};

// Update projections
const updateProjections = () => {
  processing.value = true;
  router.get(route('budget.account.balance-projection', { budget: props.budget.id, account: props.account.id }), {
    months: form.value.months,
    scenario: form.value.scenario,
    groupBy: form.value.groupBy
  }, {
    onFinish: () => {
      processing.value = false;
    }
  });
};
</script>
