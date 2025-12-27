<template>
  <Head title="Properties" />

  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
          Properties - {{ budget.name }}
        </h2>
        <Link
          :href="route('budgets.properties.create', budget.id)"
          class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
        >
          <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Add Asset
        </Link>
      </div>
    </template>

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
          <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Asset Value</div>
            <div class="mt-2 text-3xl font-bold text-gray-900 dark:text-gray-100">
              {{ formatCurrency(totalAssetValue) }}
            </div>
          </div>
          
          <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Linked Loan Balance</div>
            <div class="mt-2 text-3xl font-bold text-red-600 dark:text-red-400">
              {{ formatCurrency(totalLinkedLoans) }}
            </div>
          </div>
          
          <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
            <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Equity</div>
            <div class="mt-2 text-3xl font-bold text-green-600 dark:text-green-400">
              {{ formatCurrency(totalEquity) }}
            </div>
          </div>
        </div>

        <!-- Properties List -->
        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <div v-if="properties.length === 0" class="text-center py-12">
              <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
              </svg>
              <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">No properties</h3>
              <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Get started by adding a property or vehicle.
              </p>
              <div class="mt-6">
                <Link
                  :href="route('budgets.properties.create', budget.id)"
                  class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                >
                  <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                  </svg>
                  Add Your First Asset
                </Link>
              </div>
            </div>

            <div v-else class="space-y-4">
              <div
                v-for="property in properties"
                :key="property.id"
                class="border border-gray-200 dark:border-gray-700 rounded-lg p-6 hover:shadow-md transition-shadow"
              >
                <div class="flex justify-between items-start">
                  <div class="flex-1">
                    <div class="flex items-center gap-3">
                      <div class="flex-shrink-0">
                        <component :is="getAssetIcon(property.type)" class="w-8 h-8 text-gray-600 dark:text-gray-400" />
                      </div>
                      <div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                          {{ property.name }}
                        </h3>
                        <p v-if="property.address" class="text-sm text-gray-500 dark:text-gray-400">
                          {{ property.address }}
                        </p>
                        <p v-else-if="property.vehicle_year && property.vehicle_make && property.vehicle_model" class="text-sm text-gray-500 dark:text-gray-400">
                          {{ property.vehicle_year }} {{ property.vehicle_make }} {{ property.vehicle_model }}
                        </p>
                      </div>
                    </div>

                    <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                      <div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Current Value</div>
                        <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                          {{ formatCurrency(property.current_value_cents) }}
                        </div>
                      </div>
                      
                      <div v-if="property.linked_accounts && property.linked_accounts.length > 0">
                        <div class="text-xs text-gray-500 dark:text-gray-400">Linked Loan</div>
                        <div class="text-lg font-semibold text-red-600 dark:text-red-400">
                          {{ formatCurrency(property.linked_loan_balance) }}
                        </div>
                      </div>
                      
                      <div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Equity</div>
                        <div class="text-lg font-semibold text-green-600 dark:text-green-400">
                          {{ formatCurrency(property.equity) }}
                        </div>
                      </div>
                    </div>

                    <div v-if="property.value_updated_at" class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                      Value updated {{ formatDate(property.value_updated_at) }}
                    </div>
                  </div>

                  <div class="flex gap-2 ml-4">
                    <Link
                      :href="route('budgets.properties.edit', [budget.id, property.id])"
                      class="inline-flex items-center px-3 py-2 border border-gray-300 dark:border-gray-600 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                    >
                      Edit
                    </Link>
                    <button
                      @click="confirmDelete(property)"
                      class="inline-flex items-center px-3 py-2 border border-red-300 dark:border-red-600 shadow-sm text-sm leading-4 font-medium rounded-md text-red-700 dark:text-red-300 bg-white dark:bg-gray-700 hover:bg-red-50 dark:hover:bg-red-900 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                    >
                      Delete
                    </button>
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
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { HomeIcon, TruckIcon, CubeIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
  budget: Object,
  properties: Array,
});

const totalAssetValue = computed(() => {
  return props.properties.reduce((sum, property) => sum + property.current_value_cents, 0);
});

const totalLinkedLoans = computed(() => {
  return props.properties.reduce((sum, property) => sum + (property.linked_loan_balance || 0), 0);
});

const totalEquity = computed(() => {
  return totalAssetValue.value - totalLinkedLoans.value;
});

const formatCurrency = (cents) => {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
  }).format(cents / 100);
};

const formatDate = (date) => {
  return new Date(date).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
};

const getAssetIcon = (type) => {
  switch (type) {
    case 'property':
      return HomeIcon;
    case 'vehicle':
      return TruckIcon;
    default:
      return CubeIcon;
  }
};

const confirmDelete = (property) => {
  if (confirm(`Are you sure you want to delete ${property.name}? This will unlink any associated loan accounts.`)) {
    router.delete(route('budgets.properties.destroy', [props.budget.id, property.id]));
  }
};
</script>

