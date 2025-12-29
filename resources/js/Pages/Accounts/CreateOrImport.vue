<template>
  <Head title="Add Account" />

  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
          Add Account
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
        <!-- Choice Cards (only show if not in a specific mode) -->
        <div v-if="!mode" class="grid md:grid-cols-2 gap-6 mb-8">
          <!-- Import from Bank Card -->
          <div 
            @click="selectMode('import')"
            class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg cursor-pointer hover:shadow-lg transition-shadow border-2 border-transparent hover:border-blue-500 dark:hover:border-blue-400"
          >
            <div class="p-8 text-center">
              <div class="flex justify-center mb-4">
                <svg class="w-16 h-16 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                </svg>
              </div>
              <h3 class="text-xl font-semibold text-gray-900 dark:text-gray-100 mb-2">
                Import from Bank
              </h3>
              <p class="text-gray-600 dark:text-gray-400 mb-4">
                Securely connect to your bank and automatically import your accounts and transactions.
              </p>
              <div class="text-sm text-gray-500 dark:text-gray-500">
                <div class="flex items-center justify-center mb-2">
                  <svg class="w-4 h-4 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                  </svg>
                  Automatic transaction sync
                </div>
                <div class="flex items-center justify-center mb-2">
                  <svg class="w-4 h-4 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                  </svg>
                  Real-time balance updates
                </div>
                <div class="flex items-center justify-center">
                  <svg class="w-4 h-4 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                  </svg>
                  Bank-level security
                </div>
              </div>
            </div>
          </div>

          <!-- Add Manually Card -->
          <div 
            @click="selectMode('manual')"
            class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg cursor-pointer hover:shadow-lg transition-shadow border-2 border-transparent hover:border-indigo-500 dark:hover:border-indigo-400"
          >
            <div class="p-8 text-center">
              <div class="flex justify-center mb-4">
                <svg class="w-16 h-16 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg>
              </div>
              <h3 class="text-xl font-semibold text-gray-900 dark:text-gray-100 mb-2">
                Add Manually
              </h3>
              <p class="text-gray-600 dark:text-gray-400 mb-4">
                Manually enter account details for accounts that can't be connected or for tracking purposes.
              </p>
              <div class="text-sm text-gray-500 dark:text-gray-500">
                <div class="flex items-center justify-center mb-2">
                  <svg class="w-4 h-4 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                  </svg>
                  Full control over details
                </div>
                <div class="flex items-center justify-center mb-2">
                  <svg class="w-4 h-4 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                  </svg>
                  No bank connection needed
                </div>
                <div class="flex items-center justify-center">
                  <svg class="w-4 h-4 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                  </svg>
                  Quick setup
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Import Mode -->
        <div v-if="mode === 'import'" class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <!-- Back to Choice Button -->
            <button
              @click="resetMode"
              class="mb-4 inline-flex items-center text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200"
            >
              <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
              </svg>
              Back to options
            </button>

            <!-- No Accounts Discovered Yet -->
            <div v-if="!discoveredAccounts.length">
              <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                Connect to Your Bank
              </h3>
              <p class="text-gray-600 dark:text-gray-400 mb-6">
                Click the button below to securely connect to your bank. We use Plaid to ensure your credentials are never stored and your data is protected with bank-level security.
              </p>
              
              <!-- Existing Connections (if any) -->
              <div v-if="existingConnections && existingConnections.length > 0" class="mb-6">
                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                  Your Connected Banks
                </h4>
                <div class="space-y-2">
                  <div 
                    v-for="connection in existingConnections" 
                    :key="connection.id"
                    class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-md"
                  >
                    <div class="flex items-center">
                      <svg class="w-5 h-5 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                      </svg>
                      <span class="text-sm font-medium text-gray-900 dark:text-gray-100">
                        {{ connection.institution_name }}
                      </span>
                    </div>
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                      {{ connection.accounts_count }} account{{ connection.accounts_count !== 1 ? 's' : '' }}
                    </span>
                  </div>
                </div>
              </div>

              <button
                @click="openPlaidLink"
                class="inline-flex items-center px-6 py-3 bg-blue-600 border border-transparent rounded-md font-semibold text-sm text-white hover:bg-blue-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
              >
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                </svg>
                Connect to Bank
              </button>
            </div>

            <!-- Accounts Discovered -->
            <div v-else>
              <div class="mb-6">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">
                  Select Accounts to Import from {{ institutionName }}
                </h3>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                  Choose which accounts you'd like to add to your budget.
                </p>
              </div>

              <div class="space-y-3 mb-6">
                <div 
                  v-for="account in discoveredAccounts" 
                  :key="account.id"
                  class="flex items-center p-4 border border-gray-200 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                >
                  <input
                    type="checkbox"
                    :id="`account-${account.id}`"
                    :value="account.id"
                    v-model="selectedAccounts"
                    class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                  />
                  <label :for="`account-${account.id}`" class="ml-3 flex-1 cursor-pointer">
                    <div class="flex justify-between items-center">
                      <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100">
                          {{ account.name }}
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                          {{ getAccountTypeDisplay(account) }} • {{ account.mask ? `****${account.mask}` : 'No account number' }}
                        </p>
                      </div>
                      <div class="text-right">
                        <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                          {{ formatCurrency(account.balances?.current || 0) }}
                        </p>
                      </div>
                    </div>
                  </label>
                </div>
              </div>

              <div class="flex justify-between items-center">
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

        <!-- Manual Mode -->
        <div v-if="mode === 'manual'" class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <!-- Back to Choice Button -->
            <button
              @click="resetMode"
              class="mb-4 inline-flex items-center text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200"
            >
              <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
              </svg>
              Back to options
            </button>

            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
              Add Account Manually
            </h3>
            
            <form @submit.prevent="submitManual">
              <div class="mb-4">
                <InputLabel for="name" value="Account Name" required />
                <TextInput
                  id="name"
                  type="text"
                  class="mt-1 block w-full"
                  v-model="form.name"
                  required
                  autofocus
                  placeholder="Name of the account"
                />
                <InputError class="mt-2" :message="form.errors.name" />
              </div>
              
              <div class="mb-4">
                <InputLabel for="type" value="Account Type" required />
                <SelectInput
                  id="type"
                  class="mt-1 block w-full"
                  v-model="form.type"
                  required
                >
                  <option value="checking">Checking</option>
                  <option value="savings">Savings</option>
                  <option value="credit card">Credit Card</option>
                  <option value="line of credit">Line of Credit</option>
                  <option value="mortgage">Mortgage</option>
                  <option value="investment">Investment</option>
                  <option value="loan">Loan</option>
                  <option value="other">Other</option>
                </SelectInput>
                <InputError class="mt-2" :message="form.errors.type" />
              </div>
              
              <div class="mb-4">
                <InputLabel for="current_balance" value="Current Balance" required />
                <div class="relative mt-1 rounded-md shadow-sm">
                  <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                    <span class="text-gray-500 sm:text-sm">$</span>
                  </div>
                  <TextInput
                    id="current_balance"
                    type="number"
                    step="0.01"
                    class="mt-1 block w-full pl-7"
                    v-model="form.current_balance"
                    required
                  />
                </div>
                <InputError class="mt-2" :message="form.errors.current_balance" />
              </div>
              
              <div class="mb-4">
                <InputLabel for="status" value="Account Status" />
                <div class="mt-2">
                  <div class="flex items-center">
                    <input
                      id="status-active"
                      type="radio"
                      name="status"
                      value="active"
                      v-model="accountStatus"
                      class="h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500"
                    />
                    <label for="status-active" class="ml-2 block text-sm text-gray-700 dark:text-gray-300">
                      Active (include in budget calculations)
                    </label>
                  </div>
                  <div class="flex items-center mt-2">
                    <input
                      id="status-excluded"
                      type="radio"
                      name="status"
                      value="excluded"
                      v-model="accountStatus"
                      class="h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500"
                    />
                    <label for="status-excluded" class="ml-2 block text-sm text-gray-700 dark:text-gray-300">
                      Excluded (don't include in budget calculations)
                    </label>
                  </div>
                </div>
              </div>
              
              <div class="flex items-center justify-end mt-6">
                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                  Create Account
                </PrimaryButton>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { onMounted, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import SelectInput from '@/Components/SelectInput.vue';
import { formatCurrency } from '@/utils/format.js';

// Props
const props = defineProps({
  budget: Object,
  linkToken: String,
  existingConnections: Array,
});

// Mode state
const mode = ref(null); // null, 'import', or 'manual'

// Import mode state
const discoveredAccounts = ref([]);
const selectedAccounts = ref([]);
const institutionName = ref('');
const importing = ref(false);

// Plaid state
let linkHandler = null;
let currentPublicToken = null;
let currentMetadata = null;

// Manual mode state
const accountStatus = ref('active');
const form = useForm({
  name: '',
  type: 'checking',
  current_balance: 0,
  include_in_budget: true
});

// Watch for changes to account status and update include_in_budget
watch(accountStatus, (newValue) => {
  form.include_in_budget = newValue === 'active';
});

// Mode selection
const selectMode = (selectedMode) => {
  mode.value = selectedMode;
  if (selectedMode === 'import') {
    // Initialize Plaid if not already done
    if (!linkHandler) {
      initializePlaidIfNeeded();
    }
  }
};

const resetMode = () => {
  mode.value = null;
  resetDiscovery();
  form.reset();
  accountStatus.value = 'active';
};

// Initialize Plaid Link
onMounted(() => {
  const script = document.createElement('script');
  script.src = 'https://cdn.plaid.com/link/v2/stable/link-initialize.js';
  script.onload = initializePlaidIfNeeded;
  document.head.appendChild(script);
});

const initializePlaidIfNeeded = () => {
  if (!window.Plaid || !props.linkToken || linkHandler) {
    return;
  }

  linkHandler = window.Plaid.create({
    token: props.linkToken,
    onSuccess: (public_token, metadata) => {
      currentPublicToken = public_token;
      currentMetadata = metadata;
      institutionName.value = metadata.institution?.name || 'Your Bank';

      // Store discovered accounts
      if (metadata.accounts && metadata.accounts.length > 0) {
        discoveredAccounts.value = metadata.accounts;

        // Pre-select all accounts by default - ensure strings and uniqueness
        selectedAccounts.value = [...new Set(metadata.accounts.map(acc => String(acc.id)))];
      } else {
        alert('No accounts were found. Please try connecting a different institution.');
      }
    },
    onExit: (err, metadata) => {
      if (err) {
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

// Submit manual form handler
const submitManual = () => {
  form.post(route('budgets.accounts.store', props.budget.id), {
    onSuccess: () => {
      form.reset();
      accountStatus.value = 'active';
    },
  });
};
</script>

