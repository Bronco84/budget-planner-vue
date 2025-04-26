<template>
  <Head :title="`Yearly Statistics - ${budget.name}`" />
  
  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
          {{ budget.name }} - Yearly Statistics
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

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
          <div class="flex justify-between items-center mb-6">
            <div class="flex items-center">
              <Link
                :href="route('budget.statistics.yearly', budget.id) + '?year=' + (year - 1)"
                class="p-2 mr-2 bg-white border rounded-md hover:bg-gray-50"
              >
                <ChevronLeftIcon class="w-5 h-5 text-gray-500" />
              </Link>
              <h2 class="text-xl font-semibold">{{ year }} Summary</h2>
              <Link
                :href="route('budget.statistics.yearly', budget.id) + '?year=' + (year + 1)"
                class="p-2 ml-2 bg-white border rounded-md hover:bg-gray-50"
              >
                <ChevronRightIcon class="w-5 h-5 text-gray-500" />
              </Link>
            </div>
            <div class="flex space-x-2">
              <Link
                :href="route('budget.statistics.monthly', [budget.id, currentMonth, year])"
                class="px-3 py-1.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 flex items-center"
              >
                <ChartBarIcon class="w-4 h-4 mr-1" />
                Monthly View
              </Link>
            </div>
          </div>

          <!-- Yearly Summary -->
          <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white border rounded-lg shadow-sm p-5">
              <h3 class="text-sm font-medium text-gray-500 mb-2">Total Income</h3>
              <div class="flex justify-between items-end">
                <div class="text-2xl font-bold text-green-600">
                  ${{ formatAmount(statistics.yearly_totals.income / 100) }}
                </div>
                <div :class="getChangeClass(statistics.yearly_totals.income_change)" class="flex items-center text-sm">
                  <ArrowUpIcon v-if="statistics.yearly_totals.income_change > 0" class="w-4 h-4 mr-1" />
                  <ArrowDownIcon v-else-if="statistics.yearly_totals.income_change < 0" class="w-4 h-4 mr-1" />
                  <MinusIcon v-else class="w-4 h-4 mr-1" />
                  {{ Math.abs(Math.round(statistics.yearly_totals.income_change)) }}%
                  <span class="ml-1 text-gray-500">vs {{ year-1 }}</span>
                </div>
              </div>
            </div>

            <div class="bg-white border rounded-lg shadow-sm p-5">
              <h3 class="text-sm font-medium text-gray-500 mb-2">Total Expenses</h3>
              <div class="flex justify-between items-end">
                <div class="text-2xl font-bold text-red-600">
                  ${{ formatAmount(Math.abs(statistics.yearly_totals.expenses / 100)) }}
                </div>
                <div :class="getChangeClass(-statistics.yearly_totals.expenses_change)" class="flex items-center text-sm">
                  <ArrowUpIcon v-if="statistics.yearly_totals.expenses_change < 0" class="w-4 h-4 mr-1" />
                  <ArrowDownIcon v-else-if="statistics.yearly_totals.expenses_change > 0" class="w-4 h-4 mr-1" />
                  <MinusIcon v-else class="w-4 h-4 mr-1" />
                  {{ Math.abs(Math.round(statistics.yearly_totals.expenses_change)) }}%
                  <span class="ml-1 text-gray-500">vs {{ year-1 }}</span>
                </div>
              </div>
            </div>

            <div class="bg-white border rounded-lg shadow-sm p-5">
              <h3 class="text-sm font-medium text-gray-500 mb-2">Net</h3>
              <div class="flex justify-between items-end">
                <div :class="getNetClass(statistics.yearly_totals.income + statistics.yearly_totals.expenses)" class="text-2xl font-bold">
                  ${{ formatAmount(Math.abs((statistics.yearly_totals.income + statistics.yearly_totals.expenses) / 100)) }}
                </div>
              </div>
            </div>
          </div>

          <!-- Charts -->
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
            <!-- Yearly Income/Expenses Chart -->
            <div class="bg-white border rounded-lg shadow-sm p-5">
              <h3 class="text-lg font-medium mb-4">Income vs Expenses</h3>
              <div class="h-64">
                <p class="text-center text-gray-500 py-10">
                  Income vs Expenses chart coming soon
                </p>
              </div>
            </div>

            <!-- Monthly Trends Chart -->
            <div class="bg-white border rounded-lg shadow-sm p-5">
              <h3 class="text-lg font-medium mb-4">Monthly Trends</h3>
              <div class="h-64">
                <p class="text-center text-gray-500 py-10">
                  Monthly trends chart coming soon
                </p>
              </div>
            </div>
          </div>

          <!-- Monthly Data Table -->
          <div class="bg-white border rounded-lg shadow-sm p-5">
            <h3 class="text-lg font-medium mb-4">Monthly Breakdown</h3>
            <div class="overflow-x-auto">
              <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                  <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Month</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Income</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Expenses</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Net</th>
                    <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">YoY Change</th>
                  </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                  <tr v-for="(monthData, month) in statistics.monthly" :key="month" class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap">
                      <Link
                        :href="route('budget.statistics.monthly', [budget.id, monthData.month_number, monthData.year])"
                        class="text-blue-600 hover:text-blue-800 hover:underline"
                      >
                        {{ month }}
                      </Link>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-green-600">
                      ${{ formatAmount(monthData.total_income / 100) }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-red-600">
                      ${{ formatAmount(Math.abs(monthData.total_expenses / 100)) }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right" :class="getNetClass(monthData.total_income + monthData.total_expenses)">
                      ${{ formatAmount(Math.abs((monthData.total_income + monthData.total_expenses) / 100)) }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-center">
                      <div class="flex items-center justify-center" :class="getChangeClass(monthData.income_change)">
                        <ArrowUpIcon v-if="monthData.income_change > 0" class="w-4 h-4 mr-1" />
                        <ArrowDownIcon v-else-if="monthData.income_change < 0" class="w-4 h-4 mr-1" />
                        <MinusIcon v-else class="w-4 h-4 mr-1" />
                        {{ Math.abs(Math.round(monthData.income_change)) }}%
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
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { 
  ArrowLeftIcon, 
  ChevronLeftIcon, 
  ChevronRightIcon, 
  ChartBarIcon,
  ArrowUpIcon,
  ArrowDownIcon,
  MinusIcon
} from '@heroicons/vue/24/outline';

// Define props
const props = defineProps({
  budget: Object,
  statistics: Object,
  year: Number
});

// Format amount
const formatAmount = (amount) => {
  return parseFloat(amount).toFixed(2);
};

// Current month for navigation
const currentMonth = computed(() => {
  const now = new Date();
  return now.getMonth() + 1;
});

// Helpers for styling
const getChangeClass = (value) => {
  if (value > 0) return 'text-green-600';
  if (value < 0) return 'text-red-600';
  return 'text-gray-600';
};

const getNetClass = (value) => {
  if (value > 0) return 'text-green-600';
  if (value < 0) return 'text-red-600';
  return 'text-gray-600';
};
</script> 