<template>
  <Head :title="'Link Account to Plaid'" />

  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
          {{ isLinked ? 'Manage Plaid Connection' : 'Link Account to Plaid' }}
        </h2>
        <Link 
          :href="route('budgets.show', budget.id)" 
          class="px-4 py-2 bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-300"
        >
          Back to Budget
        </Link>
      </div>
    </template>

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <!-- Account Information -->
            <div class="mb-6">
              <h3 class="text-lg font-medium text-gray-900 mb-2">Account Details</h3>
              <div class="bg-gray-50 p-4 rounded">
                <div class="flex justify-between items-center">
                  <div>
                    <p class="text-lg font-medium text-gray-900">{{ account.name }}</p>
                    <p class="text-sm text-gray-500 capitalize">{{ account.type }}</p>
                  </div>
                  <div class="text-lg font-medium" :class="account.current_balance_cents >= 0 ? 'text-green-600' : 'text-red-600'">
                    {{ formatCurrency(account.current_balance_cents) }}
                  </div>
                </div>
              </div>
            </div>

            <!-- Plaid Connection Status -->
            <div v-if="isLinked" class="mb-6">
              <h3 class="text-lg font-medium text-gray-900 mb-2">Plaid Connection</h3>
              <div class="bg-blue-50 p-4 rounded border border-blue-200">
                <div class="flex justify-between items-start">
                  <div>
                    <p class="font-medium text-blue-800">{{ plaidAccount.institution_name }}</p>
                    <p class="text-sm text-blue-600">
                      Last synced: <PlaidSyncTimestamp
                        :timestamp="plaidAccount.plaid_connection?.last_sync_at"
                        format="absolute"
                      />
                    </p>
                  </div>
                  <div class="flex space-x-2">
                    <button
                      @click="syncTransactions"
                      class="inline-flex items-center px-3 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                      :disabled="syncInProgress"
                    >
                      <svg v-if="syncInProgress" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                      </svg>
                      <span>{{ syncInProgress ? 'Syncing...' : 'Sync Transactions' }}</span>
                    </button>
                    <button
                      @click="updateBalance"
                      class="inline-flex items-center px-3 py-2 bg-green-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
                      :disabled="balanceUpdateInProgress"
                    >
                      <svg v-if="balanceUpdateInProgress" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                      </svg>
                      <span>{{ balanceUpdateInProgress ? 'Updating...' : 'Update Balance' }}</span>
                    </button>
                    <button
                      @click="confirmDisconnect"
                      class="inline-flex items-center px-3 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                    >
                      Disconnect
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Connect Button -->
            <div v-if="!isLinked" class="text-center py-8">
              <p class="text-sm text-gray-600 mb-6">
                Connect this account to your bank to automatically import transactions and update balances.
                <br>
                Your banking credentials are never stored on our servers.
              </p>
              <button
                @click="openPlaidLink"
                class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-sm text-white uppercase tracking-widest hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
              >
                Connect to Bank
              </button>
            </div>

            <form
              ref="plaidForm"
              method="POST"
              :action="route('plaid.store', [budget.id, account.id])"
              class="hidden"
            >
              <input type="hidden" name="_token" :value="csrf">
              <input type="hidden" name="public_token" ref="publicTokenInput">
              <input type="hidden" name="metadata" ref="metadataInput">
            </form>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PlaidSyncTimestamp from '@/Components/PlaidSyncTimestamp.vue';
import { formatCurrency } from '@/utils/format.js';

// Props
const props = defineProps({
  budget: Object,
  account: Object,
  linkToken: String,
  isLinked: Boolean,
  plaidAccount: Object,
});

// Form refs
const plaidForm = ref(null);
const publicTokenInput = ref(null);
const metadataInput = ref(null);

// State
const syncInProgress = ref(false);
const balanceUpdateInProgress = ref(false);
const csrf = usePage().props.csrf || '';

// Plaid Link handler
let linkHandler = null;

// Initialize Plaid Link
onMounted(() => {
  // Load Plaid Link script
  const script = document.createElement('script');
  script.src = 'https://cdn.plaid.com/link/v2/stable/link-initialize.js';
  script.onload = initializePlaid;
  document.head.appendChild(script);
});

const initializePlaid = () => {
  if (!window.Plaid) {
    console.error('Plaid Link script failed to load');
    return;
  }

  linkHandler = window.Plaid.create({
    token: props.linkToken,
    onSuccess: (public_token, metadata) => {
      if (publicTokenInput.value && metadataInput.value) {
        publicTokenInput.value.value = public_token;
        metadataInput.value.value = JSON.stringify(metadata);
        
        // Make sure metadata has the necessary structure for our controller
        const enhancedMetadata = {
          ...metadata,
          item: metadata.item || { id: metadata.item_id || `plaid-item-${Date.now()}` }
        };
        
        // Use Inertia to post the data instead of a form submission
        router.post(route('plaid.store', [props.budget.id, props.account.id]), {
          public_token: public_token,
          metadata: enhancedMetadata
        });
      }
    },
    onExit: (err, metadata) => {
      if (err) {
        console.error('Plaid Link error:', err);
        
        // Handle specific error cases
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

const syncTransactions = () => {
  syncInProgress.value = true;
  router.post(
    route('plaid.sync', [props.budget.id, props.account.id]),
    {},
    {
      onSuccess: (page) => {
        // Debug: log all flash data
        console.log('Sync response - Flash data:', page.props.flash);
        console.log('Sync response - All props:', page.props);

        // Show success or error message if available
        if (page.props.flash && page.props.flash.message) {
          alert('Success: ' + page.props.flash.message);
        } else if (page.props.flash && page.props.flash.error) {
          alert('Error: ' + page.props.flash.error);
        } else {
          console.log('No flash message found in response');
          // Let's also check if there's any error in the page props directly
          if (page.props.error) {
            alert('Page Error: ' + page.props.error);
          }
        }
      },
      onError: (errors) => {
        console.log('Sync onError called with:', errors);
        // Show error message if available
        if (errors.message) {
          alert('OnError: ' + errors.message);
        }
      },
      onFinish: () => {
        syncInProgress.value = false;
      }
    }
  );
};

const updateBalance = () => {
  balanceUpdateInProgress.value = true;
  router.post(
    route('plaid.balance', [props.budget.id, props.account.id]),
    {},
    {
      onSuccess: (page) => {
        // Show success or error message if available
        if (page.props.flash && page.props.flash.message) {
          alert(page.props.flash.message);
        } else if (page.props.flash && page.props.flash.error) {
          alert(page.props.flash.error);
        }
      },
      onError: (errors) => {
        // Show error message if available
        if (errors.message) {
          alert(errors.message);
        }
      },
      onFinish: () => {
        balanceUpdateInProgress.value = false;
      }
    }
  );
};

const confirmDisconnect = () => {
  if (confirm('Are you sure you want to disconnect from Plaid? This will not delete any imported transactions.')) {
    router.delete(route('plaid.destroy', [props.budget.id, props.account.id]));
  }
};
</script> 