<template>
  <Head title="Budgets" />

  <AuthenticatedLayout>
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 leading-tight">Budgets</h2>
    </template>

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6 text-gray-900">
            <div class="flex justify-between items-center mb-6">
              <h3 class="text-lg font-medium text-gray-900">Your Budgets</h3>
            </div>

            <div v-if="budgets.length === 0" class="text-center py-16 text-gray-500">
              <DocumentTextIcon class="w-16 h-16 mx-auto mb-4" />
              <h3 class="text-lg font-medium mb-1">No budgets yet</h3>
              <p class="mb-4">Create your first budget to start tracking your finances</p>
              <Link
                :href="route('budgets.create')"
                class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
              >
                <PlusIcon class="w-4 h-4 mr-2" />
                Create Budget
              </Link>
            </div>

            <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
              <div
                v-for="budget in budgets"
                :key="budget.id"
                class="bg-white border rounded-lg shadow-sm hover:shadow-md transition-shadow duration-200"
              >
                <div class="p-5">
                  <div class="flex justify-between items-start">
                    <div>
                      <h4 class="text-lg font-medium text-gray-900 mb-1">{{ budget.name }}</h4>
                      <p v-if="budget.description" class="text-gray-600 text-sm mb-3">
                        {{ budget.description }}
                      </p>
                    </div>
                    <div class="flex space-x-1">
                      <Link
                        :href="route('budgets.edit', budget.id)"
                        class="text-gray-400 hover:text-gray-500"
                        title="Edit"
                      >
                        <PencilIcon class="w-5 h-5" />
                      </Link>
                    </div>
                  </div>

                  <div class="mt-3">
                    <div class="flex justify-between text-sm mb-1">
                      <span class="text-gray-600">Budget Amount</span>
                      <span class="font-medium">${{ formatAmount(budget.total_amount) }}</span>
                    </div>
                    <div class="flex justify-between text-sm mb-1">
                      <span class="text-gray-600">Remaining</span>
                      <span class="font-medium">${{ formatAmount(budget.remaining_amount) }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                      <span class="text-gray-600">Period</span>
                      <span class="font-medium">{{ formatDate(budget.start_date) }} - {{ formatDate(budget.end_date) }}</span>
                    </div>
                  </div>

                  <div class="mt-4">
                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                      <div
                        class="h-2.5 rounded-full"
                        :class="getBudgetProgressColor(budget.percent_used)"
                        :style="{ width: `${isNaN(budget.percent_used) ? 0 : Math.min(budget.percent_used, 100)}%` }"
                      ></div>
                    </div>
                    <div class="flex justify-between mt-1 text-xs text-gray-500">
                      <span>{{ isNaN(budget.percent_used) ? '0' : Math.round(budget.percent_used) }}% used</span>
                      <span>{{ isNaN(budget.percent_used) ? '100' : Math.min(100 - Math.round(budget.percent_used), 100) }}% remaining</span>
                    </div>
                  </div>

                  <div class="mt-5 pt-4 border-t flex justify-between">
                    <Link
                      :href="route('budgets.show', budget.id)"
                      class="inline-flex items-center justify-center px-3 py-1.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                    >
                      View Details
                    </Link>
                    <Link
                      :href="route('budget.transaction.create', budget.id)"
                      class="inline-flex items-center justify-center px-3 py-1.5 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-md shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                    >
                      Add Transaction
                    </Link>
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
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { PlusIcon, DocumentTextIcon, PencilIcon } from '@heroicons/vue/24/outline';

// Define props
const props = defineProps({
  budgets: Array
});

// Format a date as MM/DD/YYYY
const formatDate = (dateString) => {
  if (!dateString) return 'N/A';
  
  try {
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return 'N/A';
    
    const month = date.getMonth() + 1;
    const day = date.getDate();
    const year = date.getFullYear();
    return `${month}/${day}/${year}`;
  } catch (error) {
    return 'N/A';
  }
};

// Format an amount with 2 decimal places
const formatAmount = (amount) => {
  if (amount === undefined || amount === null || isNaN(amount)) {
    return '0.00';
  }
  
  return parseFloat(amount).toFixed(2);
};

// Get appropriate color class based on percentage used
const getBudgetProgressColor = (percentUsed) => {
  const percent = parseFloat(percentUsed);
  
  if (isNaN(percent)) return 'bg-gray-300';
  if (percent >= 90) return 'bg-red-500';
  if (percent >= 75) return 'bg-yellow-500';
  return 'bg-green-500';
};
</script> 