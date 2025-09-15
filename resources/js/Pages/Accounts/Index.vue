<template>
  <Head :title="`${budget.name} - Account Settings`" />

  <div class="py-6">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
      <!-- Header -->
      <div class="sm:flex sm:items-center sm:justify-between mb-6">
        <div>
          <h1 class="text-2xl font-semibold text-gray-900">Account Settings</h1>
          <p class="mt-2 text-sm text-gray-700">
            Manage which accounts are included in your total balance calculation for {{ budget.name }}.
          </p>
        </div>
        <div class="mt-4 sm:mt-0">
          <Link
            :href="route('budgets.show', budget.id)"
            class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
          >
            ← Back to Budget
          </Link>
        </div>
      </div>

      <!-- Balance Summary -->
      <div class="bg-white shadow rounded-lg p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <div class="text-sm font-medium text-gray-500">Total Balance (All Accounts)</div>
            <div class="text-2xl font-bold mt-1" :class="totalBalance >= 0 ? 'text-gray-900' : 'text-red-600'">
              {{ formatCurrency(totalBalance) }}
            </div>
          </div>
          <div>
            <div class="text-sm font-medium text-blue-600">Included in Budget Total</div>
            <div class="text-2xl font-bold mt-1" :class="totalIncludedBalance >= 0 ? 'text-blue-700' : 'text-red-600'">
              {{ formatCurrency(totalIncludedBalance) }}
            </div>
            <div class="text-xs text-blue-500 mt-1">Only includes selected accounts below</div>
          </div>
        </div>
      </div>

      <!-- Account Groups -->
      <div class="space-y-6">
        <div
          v-for="group in groupedAccounts"
          :key="group.id"
          class="bg-white shadow rounded-lg overflow-hidden"
        >
          <!-- Group Header -->
          <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
            <div class="flex items-center justify-between">
              <div>
                <h3 class="text-lg font-medium text-gray-900">{{ group.name }}</h3>
                <p class="text-sm text-gray-500">{{ group.account_count }} {{ group.account_count === 1 ? 'account' : 'accounts' }}</p>
              </div>
              <div class="text-sm text-gray-600">
                Group Total: {{ formatCurrency(group.total_balance) }}
              </div>
            </div>
          </div>

          <!-- Accounts List -->
          <div class="divide-y divide-gray-200">
            <div
              v-for="account in group.accounts"
              :key="account.id"
              class="px-6 py-4 hover:bg-gray-50"
            >
              <div class="flex items-center justify-between">
                <div class="flex-1">
                  <div class="flex items-center space-x-3">
                    <div>
                      <div class="text-sm font-medium text-gray-900">{{ account.name }}</div>
                      <div class="text-sm text-gray-500 capitalize">{{ account.type }}</div>
                    </div>
                  </div>
                </div>
                
                <div class="flex items-center space-x-6">
                  <!-- Account Balance -->
                  <div class="text-right">
                    <div class="text-sm font-medium" :class="account.current_balance_cents >= 0 ? 'text-gray-900' : 'text-red-600'">
                      {{ formatCurrency(account.current_balance_cents) }}
                    </div>
                  </div>

                  <!-- Inclusion Toggle -->
                  <div class="flex items-center space-x-3">
                    <span class="text-sm text-gray-700">Include in Total</span>
                    <label class="relative inline-flex items-center cursor-pointer">
                      <input
                        type="checkbox"
                        :checked="account.included_in_total"
                        @change="toggleAccountInclusion(account)"
                        class="sr-only peer"
                        :data-testid="`account-toggle-${account.id}`"
                      >
                      <div class="relative w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                    </label>
                  </div>
                </div>
              </div>
              
              <!-- Inclusion Status -->
              <div v-if="!account.included_in_total" class="mt-2">
                <div class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                  Excluded from total balance
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Help Text -->
      <div class="mt-8 bg-blue-50 border border-blue-200 rounded-lg p-4">
        <div class="flex">
          <div class="flex-shrink-0">
            <svg class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
            </svg>
          </div>
          <div class="ml-3">
            <h3 class="text-sm font-medium text-blue-800">About Account Inclusion</h3>
            <div class="mt-2 text-sm text-blue-700">
              <p>
                <strong>Included accounts</strong> count toward your budget's total balance. 
                <strong>Excluded accounts</strong> still appear in your budget but don't affect the total.
              </p>
              <p class="mt-1">
                <strong>Tip:</strong> Consider excluding credit cards and loans from your total since they represent debt, not assets.
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { formatCurrency } from '@/utils/format.js';

// Define props
const props = defineProps({
  budget: Object,
  groupedAccounts: Object,
  accounts: Array,
  totalBalance: Number,
  totalIncludedBalance: Number,
});

// Reactive total for updates
const totalIncludedBalance = ref(props.totalIncludedBalance);

// Function to toggle account inclusion in total balance
const toggleAccountInclusion = async (account) => {
  try {
    console.log('Toggling account:', account.name, 'ID:', account.id, 'Current:', account.included_in_total);
    
    const response = await window.axios.post(route('preferences.toggle-account-inclusion'), {
      account_id: account.id,
      included: !account.included_in_total,
    });

    console.log('Response:', response.data);
    
    // Update the local account state
    account.included_in_total = !account.included_in_total;
    
    // Update the total included balance display
    if (response.data.total_included_balance !== undefined) {
      totalIncludedBalance.value = response.data.total_included_balance;
    }
    
    console.log('Account inclusion toggled:', account.name, account.included_in_total ? 'included' : 'excluded');
  } catch (error) {
    console.error('Error toggling account inclusion:', error);
    if (error.response) {
      console.error('Response data:', error.response.data);
      console.error('Response status:', error.response.status);
    }
  }
};
</script>
