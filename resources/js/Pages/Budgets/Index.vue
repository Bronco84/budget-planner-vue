<template>
  <Head title="Budgets" />

  <AuthenticatedLayout>
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
import { formatCurrency } from '@/utils/format.js';

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

</script> 