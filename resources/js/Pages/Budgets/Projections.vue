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
              <p class="text-3xl font-bold" :class="projections.starting_balance >= 0 ? 'text-green-600' : 'text-red-600'">
                ${{ formatCurrency(projections.starting_balance) }}
              </p>
              <p class="text-sm text-gray-500 mt-1">As of {{ formatDate(form.start_date) }}</p>
            </div>
          </div>
          
          <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6">
              <h3 class="text-lg font-medium text-gray-900 mb-2">Projected Income</h3>
              <p class="text-3xl font-bold text-green-600">
                ${{ formatCurrency(totalProjectedIncome) }}
              </p>
              <p class="text-sm text-gray-500 mt-1">Over {{ form.months }} months</p>
            </div>
          </div>
          
          <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-6">
              <h3 class="text-lg font-medium text-gray-900 mb-2">Projected Expenses</h3>
              <p class="text-3xl font-bold text-red-600">
                ${{ formatCurrency(totalProjectedExpenses) }}
              </p>
              <p class="text-sm text-gray-500 mt-1">Over {{ form.months }} months</p>
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
                  </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                  <tr v-for="(stats, month) in projections.monthly_totals" :key="month">
                    <td class="px-6 py-4 whitespace-nowrap">
                      <div class="text-sm font-medium text-gray-900">{{ month }}</div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                      <div class="text-sm text-green-600">
                        ${{ formatCurrency(stats.income) }}
                      </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                      <div class="text-sm text-red-600">
                        ${{ formatCurrency(stats.expense) }}
                      </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                      <div class="text-sm" :class="stats.net >= 0 ? 'text-green-600' : 'text-red-600'">
                        ${{ formatCurrency(stats.net) }}
                      </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                      <div class="text-sm font-medium" :class="stats.ending_balance >= 0 ? 'text-green-600' : 'text-red-600'">
                        ${{ formatCurrency(stats.ending_balance) }}
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
            
            <div v-if="Object.keys(projections.transactions).length === 0" class="text-center py-10 text-gray-500">
              <p>No projected transactions found.</p>
            </div>
            
            <div v-else>
              <div v-for="(days, month) in projections.transactions" :key="month" class="mb-8">
                <h4 class="text-xl font-medium text-gray-800 mb-4 border-b pb-2">{{ month }}</h4>
                
                <div v-for="(transactions, day) in days" :key="`${month}-${day}`" class="mb-4">
                  <div class="flex items-center mb-2">
                    <div class="w-12 text-sm font-medium text-gray-700">{{ day }}</div>
                    <div class="flex-1">
                      <div v-for="transaction in transactions" :key="transaction.id || transaction.date" 
                           class="flex justify-between items-start py-2 border-b border-gray-100 hover:bg-gray-50 transition-colors"
                           :class="{'opacity-70': transaction.is_projected}">
                        <div class="flex flex-col">
                          <div class="flex items-center">
                            <div class="font-medium text-gray-900">{{ transaction.description }}</div>
                            <div v-if="transaction.is_projected" class="ml-2 px-1.5 py-0.5 text-xs rounded-full bg-blue-100 text-blue-800">
                              Projected
                            </div>
                          </div>
                          <div v-if="transaction.category" class="text-xs text-gray-500">
                            {{ transaction.category }}
                          </div>
                        </div>
                        <div class="flex items-center">
                          <div class="text-sm font-medium mr-4" :class="transaction.amount_in_cents >= 0 ? 'text-green-600' : 'text-red-600'">
                            ${{ formatCurrency(transaction.amount_in_cents) }}
                          </div>
                          <div class="text-xs text-gray-500">
                            Balance: ${{ formatCurrency(transaction.running_balance) }}
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
  return Object.values(props.projections.monthly_totals).reduce((sum, month) => {
    return sum + month.income;
  }, 0);
});

const totalProjectedExpenses = computed(() => {
  return Object.values(props.projections.monthly_totals).reduce((sum, month) => {
    return sum + Math.abs(month.expense);
  }, 0);
});

// Format helpers
const formatCurrency = (cents) => {
  const dollars = Math.abs(cents) / 100;
  return dollars.toFixed(2);
};

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