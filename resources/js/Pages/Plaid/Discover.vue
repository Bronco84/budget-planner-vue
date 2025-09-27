<template>
  <Head :title="'Import Accounts from Bank'" />

  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
          Import Accounts from Bank
        </h2>
        <Link 
          :href="route('budgets.show', budget.id)" 
          class="px-4 py-2 bg-gray-200 dark:bg-gray-700 border border-transparent rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-300 dark:hover:bg-gray-600"
        >
          Back to Budget
        </Link>
      </div>
    </template>

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <!-- Introduction -->
            <div class="mb-8">
              <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-3">Discover Your Bank Accounts</h3>
              <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                <div class="flex">
                  <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                      <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                    </svg>
                  </div>
                  <div class="ml-3">
                    <p class="text-sm text-blue-700 dark:text-blue-300">
                      Connect to your bank to discover <strong>all available accounts</strong> including checking, savings, 
                      credit cards, mortgages, investments, and loans. Select which accounts you'd like to import 
                      into your budget for automatic transaction syncing.
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Account Discovery Section -->
            <div v-if="!discoveredAccounts.length" class="text-center py-12">
              <div class="mx-auto max-w-md">
                <svg class="mx-auto h-12 w-12 text-gray-400 dark:text-gray-500 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v12m6-6H6" />
                </svg>
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">
                  Connect to Your Bank
                </h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">
                  Securely connect to your bank to discover all your accounts. 
                  Your credentials are encrypted and never stored on our servers.
                </p>
                <button
                  @click="openPlaidLink"
                  :disabled="!linkToken"
                  class="inline-flex items-center px-6 py-3 bg-indigo-600 border border-transparent rounded-md font-semibold text-sm text-white uppercase tracking-widest hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                  </svg>
                  Connect Bank Account
                </button>
              </div>
            </div>

            <!-- Discovered Accounts -->
            <div v-if="discoveredAccounts.length" class="space-y-6">
              <div class="flex justify-between items-center">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                  Available Accounts at {{ institutionName }}
                </h3>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                  Found {{ discoveredAccounts.length }} accounts
                </div>
              </div>

              <!-- Account Selection -->
              <div class="space-y-3">
                <div v-for="account in discoveredAccounts" :key="account.id"
                     class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                  <label class="flex items-start space-x-3 cursor-pointer">
                    <input
                      type="checkbox"
                      :value="String(account.id)"
                      v-model="selectedAccounts"
                      class="mt-1 h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 dark:border-gray-600 rounded"
                    >
                    <div class="flex-1 min-w-0">
                      <div class="flex items-center justify-between">
                        <div>
                          <p class="text-base font-medium text-gray-900 dark:text-gray-100">
                            {{ account.name }}
                          </p>
                          <div class="flex items-center space-x-2 mt-1">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-300 capitalize">
                              {{ getAccountTypeDisplay(account) }}
                            </span>
                            <span v-if="account.mask" class="text-sm text-gray-500 dark:text-gray-400">
                              •••• {{ account.mask }}
                            </span>
                          </div>
                        </div>
                        <div v-if="account.balances && account.balances.current !== null" 
                             class="text-right">
                          <p class="text-lg font-semibold" 
                             :class="account.balances.current >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                            {{ formatCurrency(account.balances.current * 100) }}
                          </p>
                          <p v-if="account.balances.available !== null && account.balances.available !== account.balances.current" 
                             class="text-sm text-gray-500 dark:text-gray-400">
                            Available: {{ formatCurrency(account.balances.available * 100) }}
                          </p>
                        </div>
                      </div>
                    </div>
                  </label>
                </div>
              </div>

              <!-- Import Actions -->
              <div class="border-t border-gray-200 dark:border-gray-700 pt-6">
                <div class="flex items-center justify-between">
                  <div class="text-sm text-gray-600 dark:text-gray-400">
                    {{ selectedAccounts.length }} of {{ discoveredAccounts.length }} accounts selected
                  </div>
                  <div class="flex space-x-3">
                    <button
                      @click="resetDiscovery"
                      class="inline-flex items-center px-4 py-2 bg-gray-300 dark:bg-gray-600 border border-transparent rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest hover:bg-gray-400 dark:hover:bg-gray-500"
                    >
                      Try Different Bank
                    </button>
                    <button
                      @click="importSelectedAccounts"
                      :disabled="!selectedAccounts.length || importing"
                      class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                      <svg v-if="importing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                      </svg>
                      {{ importing ? 'Importing...' : `Import ${selectedAccounts.length} Account${selectedAccounts.length !== 1 ? 's' : ''}` }}
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
import { Head, Link, router } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatCurrency } from '@/utils/format.js';

// Props
const props = defineProps({
  budget: Object,
  linkToken: String,
});

// State
const discoveredAccounts = ref([]);
const selectedAccounts = ref([]);
const institutionName = ref('');
const importing = ref(false);

// Plaid state
let linkHandler = null;
let currentPublicToken = null;
let currentMetadata = null;

// Initialize Plaid Link
onMounted(() => {
  const script = document.createElement('script');
  script.src = 'https://cdn.plaid.com/link/v2/stable/link-initialize.js';
  script.onload = initializePlaid;
  document.head.appendChild(script);
});

const initializePlaid = () => {
  if (!window.Plaid || !props.linkToken) {
    console.error('Plaid Link script failed to load or no link token');
    return;
  }

  linkHandler = window.Plaid.create({
    token: props.linkToken,
    onSuccess: (public_token, metadata) => {
      console.log('Plaid Link Success:', metadata);
      
      currentPublicToken = public_token;
      currentMetadata = metadata;
      institutionName.value = metadata.institution?.name || 'Your Bank';
      
      // Store discovered accounts
      if (metadata.accounts && metadata.accounts.length > 0) {
        console.log('Raw Plaid accounts:', metadata.accounts);
        console.log('Account count:', metadata.accounts.length);

        // Check for duplicates or type issues - use 'id' field, not 'account_id'
        const accountIds = metadata.accounts.map(acc => acc.id);
        console.log('Account IDs:', accountIds);
        console.log('Account ID types:', accountIds.map(id => typeof id));
        console.log('Unique account IDs:', [...new Set(accountIds)]);

        discoveredAccounts.value = metadata.accounts;

        // Pre-select all accounts by default - ensure strings and uniqueness
        selectedAccounts.value = [...new Set(metadata.accounts.map(acc => String(acc.id)))];

        console.log('Discovered accounts set:', discoveredAccounts.value.length);
        console.log('Selected account IDs:', selectedAccounts.value);
      } else {
        alert('No accounts were found. Please try connecting a different institution.');
      }
    },
    onExit: (err, metadata) => {
      if (err) {
        console.error('Plaid Link error:', err);
        
        if (err.error_type === 'INSTITUTION_ERROR' && err.error_code === 'INSTITUTION_REGISTRATION_REQUIRED') {
          alert(`Institution Registration Required: ${err.display_message || 'This institution requires special registration. Please contact support for assistance connecting this account.'}`);
        } else if (err.error_message) {
          alert(`Connection Error: ${err.error_message}`);
        }
      }
    },
  });
};

const openPlaidLink = () => {
  if (linkHandler) {
    linkHandler.open();
  } else {
    console.error('Plaid Link not initialized');
  }
};

const resetDiscovery = () => {
  discoveredAccounts.value = [];
  selectedAccounts.value = [];
  institutionName.value = '';
  currentPublicToken = null;
  currentMetadata = null;
};

const importSelectedAccounts = () => {
  if (!currentPublicToken || !currentMetadata || !selectedAccounts.value.length) {
    alert('Please select at least one account to import.');
    return;
  }
  
  importing.value = true;
  
  router.post(route('plaid.import', props.budget.id), {
    public_token: currentPublicToken,
    metadata: currentMetadata,
    selected_accounts: selectedAccounts.value,
  }, {
    onFinish: () => {
      importing.value = false;
    },
    onError: (errors) => {
      console.error('Import errors:', errors);
    }
  });
};

const getAccountTypeDisplay = (account) => {
  const subtype = account.subtype || account.type || 'unknown';
  
  // Map to more readable formats
  const typeMap = {
    'checking': 'Checking',
    'savings': 'Savings', 
    'credit card': 'Credit Card',
    'cd': 'Certificate of Deposit',
    'money market': 'Money Market',
    'hsa': 'Health Savings',
    'ira': 'Traditional IRA',
    'roth': 'Roth IRA',
    '401k': '401(k)',
    '403B': '403(b)',
    '457b': '457(b)',
    'brokerage': 'Brokerage',
    'mutual fund': 'Mutual Fund',
    'mortgage': 'Mortgage',
    'auto': 'Auto Loan',
    'student': 'Student Loan',
    'home equity': 'Home Equity',
    'line of credit': 'Line of Credit',
    'business': 'Business Loan',
    'paypal': 'PayPal',
    'prepaid': 'Prepaid',
    'depository': 'Bank Account',
    'credit': 'Credit Account',
    'loan': 'Loan',
    'investment': 'Investment',
  };
  
  return typeMap[subtype.toLowerCase()] || subtype.charAt(0).toUpperCase() + subtype.slice(1);
};
</script>
