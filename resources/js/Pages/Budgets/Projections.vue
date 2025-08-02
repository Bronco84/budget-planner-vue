<template>
  <Head :title="`${budget.name} - Projections`" />

  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ budget.name }} - Projections</h2>
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
              <div>
                <InputLabel for="start_date" value="Start Date" />
                <TextInput
                  id="start_date"
                  type="date"
                  class="mt-1 block w-full"
                  v-model="form.start_date"
                  required
                />
              </div>

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

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
          <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6">
              <h3 class="text-lg font-medium text-gray-900 mb-2">Starting Balance</h3>
              <p v-if="props.projections && props.projections.starting_balance !== undefined" class="text-3xl font-bold" :class="props.projections.starting_balance >= 0 ? 'text-green-600' : 'text-red-600'">
                {{ formatCurrency(props.projections.starting_balance) }}
              </p>
              <p v-else class="text-3xl font-bold text-gray-400">$0.00</p>
              <p class="text-sm text-gray-500 mt-1">As of {{ formatDate(form.start_date) }}</p>
            </div>
          </div>

          <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6">
              <h3 class="text-lg font-medium text-gray-900 mb-2">Projected Cash Flow</h3>
              <div class="flex items-end">
                <p class="text-3xl font-bold" :class="netCashFlow >= 0 ? 'text-green-600' : 'text-red-600'">
                  {{ formatCurrency(netCashFlow) }}
                </p>
                <p class="text-sm ml-2 mb-1" :class="netCashFlow >= 0 ? 'text-green-600' : 'text-red-600'">
                  {{ netCashFlow >= 0 ? 'Surplus' : 'Deficit' }}
                </p>
              </div>
              <div class="flex justify-between text-sm text-gray-500 mt-2">
                <p>Income: <span class="text-green-600">${{ formatCurrency(totalProjectedIncome) }}</span></p>
                <p>Expenses: <span class="text-red-600">${{ formatCurrency(totalProjectedExpenses) }}</span></p>
              </div>
              <p class="text-sm text-gray-500 mt-1">Over {{ form.months }} months</p>
            </div>
          </div>

          <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6">
              <h3 class="text-lg font-medium text-gray-900 mb-2">Ending Balance</h3>
              <p class="text-3xl font-bold" :class="expectedEndingBalance >= 0 ? 'text-green-600' : 'text-red-600'">
                {{ formatCurrency(expectedEndingBalance) }}
              </p>
              <div class="flex items-center mt-2">
                <div class="bg-gray-200 h-2 flex-grow rounded-full overflow-hidden">
                  <div
                    class="h-full rounded-full"
                    :class="balanceChangePercent >= 0 ? 'bg-green-500' : 'bg-red-500'"
                    :style="`width: ${Math.min(Math.abs(balanceChangePercent), 100)}%`"
                  ></div>
                </div>
                <span class="text-xs ml-2" :class="balanceChangePercent >= 0 ? 'text-green-600' : 'text-red-600'">
                  {{ balanceChangePercent >= 0 ? '+' : '' }}{{ balanceChangePercent.toFixed(1) }}%
                </span>
              </div>
              <p class="text-sm text-gray-500 mt-1">
                Projected on {{ getEndDate() }}
              </p>
            </div>
          </div>
        </div>

        <!-- Projected Monthly Summaries -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
          <div class="p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Monthly Projections</h3>

            <div class="overflow-x-auto">
              <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                  <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Month</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Income</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Expenses</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Net</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ending Balance</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Trend</th>
                  </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                  <tr v-for="(stats, month, index) in props.projections && props.projections.monthly_totals ? props.projections.monthly_totals : {}" :key="month">
                    <td class="px-6 py-4 whitespace-nowrap">
                      <div class="text-sm font-medium text-gray-900">{{ month }}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                      <div class="text-sm text-green-600">
                        {{ formatCurrency(stats.income) }}
                      </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                      <div class="text-sm text-red-600">
                        {{ formatCurrency(stats.expense) }}
                      </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                      <div class="text-sm" :class="stats.net >= 0 ? 'text-green-600' : 'text-red-600'">
                        {{ formatCurrency(stats.net) }}
                      </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                      <div class="text-sm font-medium" :class="stats.ending_balance >= 0 ? 'text-green-600' : 'text-red-600'">
                        {{ formatCurrency(stats.ending_balance) }}
                      </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                      <div class="flex items-center">
                        <!-- Previous month comparison, only show if not first month -->
                        <template v-if="index > 0">
                          <div class="w-16 text-xs font-medium"
                               :class="stats.net >= getPreviousMonthNet(month) ? 'text-green-600' : 'text-red-600'">
                            {{ getNetChangeText(stats.net, getPreviousMonthNet(month)) }}
                          </div>
                          <div class="ml-2">
                            <svg v-if="stats.net > getPreviousMonthNet(month)" class="h-5 w-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                              <path fill-rule="evenodd" d="M12 7a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 112 0v.586l3.293-3.293a1 1 0 011.414 0l3 3a1 1 0 010 1.414l-3 3a1 1 0 01-1.414-1.414L11.586 7H12z" clip-rule="evenodd" />
                            </svg>
                            <svg v-else-if="stats.net < getPreviousMonthNet(month)" class="h-5 w-5 text-red-500" fill="currentColor" viewBox="0 0 20 20">
                              <path fill-rule="evenodd" d="M12 13a1 1 0 01-1-1V5a1 1 0 011-1h2a1 1 0 010 2h-.586l3.293 3.293a1 1 0 01-1.414 1.414L12 7.414V12a1 1 0 01-1 1z" clip-rule="evenodd" />
                            </svg>
                            <svg v-else class="h-5 w-5 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                              <path fill-rule="evenodd" d="M18 10a1 1 0 01-1 1H3a1 1 0 110-2h14a1 1 0 011 1z" clip-rule="evenodd" />
                            </svg>
                          </div>
                        </template>
                        <div v-else class="text-xs text-gray-500">First month</div>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Projected Transactions -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Projected Transactions</h3>

            <div v-if="!props.projections || !props.projections.transactions || Object.keys(props.projections.transactions).length === 0" class="text-center py-10 text-gray-500">
              <p>No projected transactions found.</p>
            </div>

            <div v-else>
              <div v-for="(days, month) in props.projections.transactions" :key="month" class="mb-8">
                <div class="flex justify-between items-center">
                  <h4 class="text-xl font-medium text-gray-800 mb-4 border-b pb-2">{{ month }}</h4>
                  <div v-if="props.projections.monthly_totals && props.projections.monthly_totals[month]" class="text-sm">
                    <span class="font-medium">Month End Balance: </span>
                    <span :class="props.projections.monthly_totals[month].ending_balance >= 0 ? 'text-green-600 font-medium' : 'text-red-600 font-medium'">
                      {{ formatCurrency(props.projections.monthly_totals[month].ending_balance) }}
                    </span>
                  </div>
                </div>

                <div v-for="(transactions, day) in days" :key="`${month}-${day}`" class="mb-4">
                  <div class="flex">
                    <div class="w-12 text-sm font-medium text-gray-700 pt-2">{{ day }}</div>
                    <div class="flex-1 border-l border-gray-200 pl-4">
                      <div v-for="transaction in transactions" :key="transaction.id || transaction.date"
                           class="flex justify-between items-start py-2 border-b border-gray-100 hover:bg-gray-50 transition-colors"
                           :class="[
                             {'opacity-70': transaction.is_projected},
                             transaction.amount_in_cents >= 0 ? 'border-l-2 border-l-green-200' : 'border-l-2 border-l-red-200'
                           ]">
                        <div class="flex flex-col pl-2">
                          <div class="flex items-center">
                            <div class="font-medium text-gray-900">{{ transaction.description }}</div>
                            <div v-if="transaction.is_projected" class="ml-2 px-1.5 py-0.5 text-xs rounded-full bg-blue-100 text-blue-800">
                              Projected
                            </div>
                            <div v-else class="ml-2 px-1.5 py-0.5 text-xs rounded-full bg-green-100 text-green-800">
                              Confirmed
                            </div>
                          </div>
                          <div class="flex items-center mt-1">
                            <div v-if="transaction.category" class="text-xs text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full">
                              {{ transaction.category }}
                            </div>
                            <div v-if="transaction.account" class="text-xs text-gray-500 ml-2">
                              {{ transaction.account.name }}
                            </div>
                          </div>
                        </div>
                        <div class="flex flex-col items-end">
                          <div class="text-sm font-medium" :class="transaction.amount_in_cents >= 0 ? 'text-green-600' : 'text-red-600'">
                            {{ formatCurrency(transaction.amount_in_cents) }}
                          </div>
                          <div class="text-xs mt-1" :class="transaction.running_balance >= 0 ? 'text-green-600' : 'text-red-600'">
                            Balance: {{ formatCurrency(transaction.running_balance) }}
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
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
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

// Define props
const props = defineProps({
  budget: Object,
  projections: Object,
  params: Object,
});

// Form state
const form = ref({
  start_date: props.params.start_date,
  months: props.params.months,
});
const processing = ref(false);

// Computed values
const totalProjectedIncome = computed(() => {
  if (!props.projections || !props.props.projections && props.projections.monthly_totals) {
    return 0;
  }
  return Object.values(props.props.projections && props.projections.monthly_totals).reduce((sum, month) => {
    return sum + month.income;
  }, 0);
});

const totalProjectedExpenses = computed(() => {
  if (!props.projections || !props.props.projections && props.projections.monthly_totals) {
    return 0;
  }
  return Object.values(props.props.projections && props.projections.monthly_totals).reduce((sum, month) => {
    return sum + Math.abs(month.expense);
  }, 0);
});

const netCashFlow = computed(() => {
  return totalProjectedIncome.value - totalProjectedExpenses.value;
});

const expectedEndingBalance = computed(() => {
  if (!props.projections || !props.props.projections && props.projections.monthly_totals) {
    return 0;
  }
  const months = Object.keys(props.props.projections && props.projections.monthly_totals);
  if (months.length === 0) return 0;

  const lastMonth = months[months.length - 1];
  return props.props.projections && props.projections.monthly_totals[lastMonth].ending_balance;
});

const balanceChangePercent = computed(() => {
  if (!props.projections || !props.props.projections && props.projections.monthly_totals || !props.props.projections && props.projections.starting_balance) {
    return 0;
  }

  const startingBalance = props.props.projections && props.projections.starting_balance;
  if (startingBalance === 0) return 0;

  const months = Object.keys(props.props.projections && props.projections.monthly_totals);
  if (months.length === 0) return 0;

  const lastMonth = months[months.length - 1];
  const lastEndingBalance = props.props.projections && props.projections.monthly_totals[lastMonth].ending_balance;

  const change = lastEndingBalance - startingBalance;

  return (change / Math.abs(startingBalance)) * 100;
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

const getEndDate = () => {
  if (!props.projections || !props.props.projections && props.projections.monthly_totals || !props.params || !props.params.start_date) {
    return 'N/A';
  }

  const months = Object.keys(props.props.projections && props.projections.monthly_totals);
  if (months.length === 0) return 'N/A';

  try {
    const date = new Date(props.params.start_date);
    date.setMonth(date.getMonth() + parseInt(props.params.months || 0));
    return date.toLocaleDateString(undefined, {
      year: 'numeric',
      month: 'long',
      day: 'numeric'
    });
  } catch (e) {
    return 'N/A';
  }
};

// Get the previous month's net amount for comparison
const getPreviousMonthNet = (currentMonth) => {
  if (!props.projections || !props.props.projections && props.projections.monthly_totals || !currentMonth) {
    return 0;
  }

  const months = Object.keys(props.props.projections && props.projections.monthly_totals);
  const currentIndex = months.indexOf(currentMonth);
  if (currentIndex <= 0) return 0;

  const previousMonth = months[currentIndex - 1];
  if (!props.props.projections && props.projections.monthly_totals[previousMonth]) return 0;

  return props.props.projections && props.projections.monthly_totals[previousMonth].net;
};

// Generate text for net change percentage
const getNetChangeText = (currentNet, previousNet) => {
  if (previousNet === 0) return 'N/A';

  const change = currentNet - previousNet;
  const percentChange = ((change / Math.abs(previousNet)) * 100).toFixed(1);

  if (change > 0) {
    return `+${percentChange}%`;
  } else if (change < 0) {
    return `${percentChange}%`;
  } else {
    return 'No change';
  }
};

// Update projections
const updateProjections = () => {
  processing.value = true;
  router.get(route('budget.projections', props.budget.id), {
    start_date: form.value.start_date,
    months: form.value.months,
  }, {
    onFinish: () => {
      processing.value = false;
    }
  });
};
</script>
