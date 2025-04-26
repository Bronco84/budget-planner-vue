<template>
  <Head :title="`Monthly Statistics - ${budget.name}`" />
  
  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
          {{ budget.name }} - Monthly Statistics
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
                :href="route('budget.statistics.monthly', [budget.id, prevMonth.month, prevMonth.year])"
                class="p-2 mr-2 bg-white border rounded-md hover:bg-gray-50"
              >
                <ChevronLeftIcon class="w-5 h-5 text-gray-500" />
              </Link>
              <h2 class="text-xl font-semibold">{{ monthName }} {{ year }}</h2>
              <Link
                :href="route('budget.statistics.monthly', [budget.id, nextMonth.month, nextMonth.year])"
                class="p-2 ml-2 bg-white border rounded-md hover:bg-gray-50"
              >
                <ChevronRightIcon class="w-5 h-5 text-gray-500" />
              </Link>
            </div>
            <Link
              :href="route('budget.statistics.yearly', budget.id)"
              class="px-3 py-1.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 flex items-center"
            >
              <ChartBarSquareIcon class="w-4 h-4 mr-1" />
              Yearly View
            </Link>
          </div>

          <!-- Statistics Summary -->
          <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white border rounded-lg shadow-sm p-5">
              <h3 class="text-sm font-medium text-gray-500 mb-2">Income</h3>
              <div class="flex justify-between items-end">
                <div class="text-2xl font-bold text-green-600">
                  ${{ formatAmount(statistics.total_income / 100) }}
                </div>
                <div :class="getChangeClass(statistics.income_change)" class="flex items-center text-sm">
                  <ArrowUpIcon v-if="statistics.income_change > 0" class="w-4 h-4 mr-1" />
                  <ArrowDownIcon v-else-if="statistics.income_change < 0" class="w-4 h-4 mr-1" />
                  <MinusIcon v-else class="w-4 h-4 mr-1" />
                  {{ Math.abs(Math.round(statistics.income_change)) }}%
                  <span class="ml-1 text-gray-500">vs {{ statistics.prev_month_name }}</span>
                </div>
              </div>
            </div>

            <div class="bg-white border rounded-lg shadow-sm p-5">
              <h3 class="text-sm font-medium text-gray-500 mb-2">Expenses</h3>
              <div class="flex justify-between items-end">
                <div class="text-2xl font-bold text-red-600">
                  ${{ formatAmount(Math.abs(statistics.total_expenses / 100)) }}
                </div>
                <div :class="getChangeClass(-statistics.expenses_change)" class="flex items-center text-sm">
                  <ArrowUpIcon v-if="statistics.expenses_change < 0" class="w-4 h-4 mr-1" />
                  <ArrowDownIcon v-else-if="statistics.expenses_change > 0" class="w-4 h-4 mr-1" />
                  <MinusIcon v-else class="w-4 h-4 mr-1" />
                  {{ Math.abs(Math.round(statistics.expenses_change)) }}%
                  <span class="ml-1 text-gray-500">vs {{ statistics.prev_month_name }}</span>
                </div>
              </div>
            </div>

            <div class="bg-white border rounded-lg shadow-sm p-5">
              <h3 class="text-sm font-medium text-gray-500 mb-2">Net</h3>
              <div class="flex justify-between items-end">
                <div :class="getNetClass(statistics.total_income + statistics.total_expenses)" class="text-2xl font-bold">
                  ${{ formatAmount(Math.abs((statistics.total_income + statistics.total_expenses) / 100)) }}
                </div>
              </div>
            </div>
          </div>

          <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Expense Distribution Chart -->
            <div class="bg-white border rounded-lg shadow-sm p-5">
              <h3 class="text-lg font-medium mb-4">Expense Distribution</h3>
              <div class="h-64">
                <p class="text-center text-gray-500 py-10">
                  Expense distribution chart coming soon
                </p>
              </div>
            </div>

            <!-- Category Comparison Chart -->
            <div class="bg-white border rounded-lg shadow-sm p-5">
              <h3 class="text-lg font-medium mb-4">Category Comparison</h3>
              <div class="h-64">
                <p class="text-center text-gray-500 py-10">
                  Category comparison chart coming soon
                </p>
              </div>
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
  ChartBarSquareIcon,
  ArrowUpIcon,
  ArrowDownIcon,
  MinusIcon
} from '@heroicons/vue/24/outline';

// Define props
const props = defineProps({
  budget: Object,
  statistics: Object,
  month: Number,
  year: Number
});

// Format amount
const formatAmount = (amount) => {
  return parseFloat(amount).toFixed(2);
};

// Get month name
const monthName = computed(() => {
  const date = new Date(props.year, props.month - 1, 1);
  return date.toLocaleString('default', { month: 'long' });
});

// Previous/next month navigation
const prevMonth = computed(() => {
  const date = new Date(props.year, props.month - 2, 1);
  return {
    month: date.getMonth() + 1,
    year: date.getFullYear()
  };
});

const nextMonth = computed(() => {
  const date = new Date(props.year, props.month, 1);
  return {
    month: date.getMonth() + 1,
    year: date.getFullYear()
  };
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