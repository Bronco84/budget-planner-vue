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
            <div v-if="isLinked" class="mb-6 space-y-4">
              <h3 class="text-lg font-medium text-gray-900">Plaid Connection</h3>
              
              <!-- Quick Actions -->
              <div class="bg-blue-50 p-4 rounded-lg border border-blue-200">
                <div class="flex justify-between items-start mb-4">
                  <div>
                    <p class="font-medium text-blue-800">{{ plaidAccount.institution_name }}</p>
                    <p class="text-sm text-blue-600">
                      Last synced: <PlaidSyncTimestamp
                        :timestamp="connectionMetadata?.last_sync_at"
                        format="absolute"
                      />
                    </p>
                  </div>
                  <span 
                    v-if="connectionMetadata"
                    class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium border"
                    :class="getStatusColor(connectionMetadata.status)"
                  >
                    {{ getStatusLabel(connectionMetadata.status) }}
                  </span>
                </div>
                <div class="flex flex-wrap gap-2">
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
                    @click="updateConnection"
                    class="inline-flex items-center px-3 py-2 bg-amber-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-amber-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-amber-500"
                    :disabled="updateConnectionInProgress"
                  >
                    <svg v-if="updateConnectionInProgress" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <span>{{ updateConnectionInProgress ? 'Loading...' : 'Re-authenticate' }}</span>
                  </button>
                  <button
                    @click="confirmDisconnect"
                    class="inline-flex items-center px-3 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                  >
                    Disconnect
                  </button>
                </div>
              </div>

              <!-- Error Message (if present) -->
              <div v-if="connectionMetadata?.error_message" class="bg-red-50 border border-red-200 rounded-lg p-4">
                <div class="flex items-start">
                  <svg class="h-5 w-5 text-red-400 mt-0.5 mr-3" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                  </svg>
                  <div class="flex-1">
                    <h4 class="text-sm font-medium text-red-800">Connection Error</h4>
                    <p class="mt-1 text-sm text-red-700">{{ connectionMetadata.error_message }}</p>
                    <p class="mt-2 text-sm text-red-600">Please try re-authenticating your connection using the button above.</p>
                  </div>
                </div>
              </div>

              <!-- Metadata Grid -->
              <div v-if="connectionMetadata" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Connection Information Card -->
                <div class="bg-white border border-gray-200 rounded-lg p-4">
                  <h4 class="text-sm font-semibold text-gray-900 mb-3 flex items-center">
                    <svg class="h-4 w-4 mr-2 text-gray-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
                    </svg>
                    Connection Information
                  </h4>
                  <dl class="space-y-2">
                    <div>
                      <dt class="text-xs text-gray-500">Plaid Item ID</dt>
                      <dd class="mt-1 flex items-center justify-between">
                        <span class="text-sm font-mono text-gray-900 truncate">{{ connectionMetadata.plaid_item_id || 'N/A' }}</span>
                        <button 
                          v-if="connectionMetadata.plaid_item_id"
                          @click="copyToClipboard(connectionMetadata.plaid_item_id, 'Item ID')"
                          class="ml-2 text-gray-400 hover:text-gray-600"
                          title="Copy to clipboard"
                        >
                          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184" />
                          </svg>
                        </button>
                      </dd>
                    </div>
                    <div>
                      <dt class="text-xs text-gray-500">Institution ID</dt>
                      <dd class="mt-1 flex items-center justify-between">
                        <span class="text-sm font-mono text-gray-900 truncate">{{ connectionMetadata.institution_id || 'N/A' }}</span>
                        <button 
                          v-if="connectionMetadata.institution_id"
                          @click="copyToClipboard(connectionMetadata.institution_id, 'Institution ID')"
                          class="ml-2 text-gray-400 hover:text-gray-600"
                          title="Copy to clipboard"
                        >
                          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184" />
                          </svg>
                        </button>
                      </dd>
                    </div>
                    <div>
                      <dt class="text-xs text-gray-500">Connection Created</dt>
                      <dd class="mt-1 text-sm text-gray-900">{{ formatDate(connectionMetadata.created_at) }}</dd>
                    </div>
                    <div>
                      <dt class="text-xs text-gray-500">Linked Accounts</dt>
                      <dd class="mt-1 text-sm text-gray-900">{{ connectionMetadata.account_count }} account(s)</dd>
                    </div>
                  </dl>
                </div>

                <!-- Account Details Card -->
                <div class="bg-white border border-gray-200 rounded-lg p-4">
                  <h4 class="text-sm font-semibold text-gray-900 mb-3 flex items-center">
                    <svg class="h-4 w-4 mr-2 text-gray-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />
                    </svg>
                    Account Details
                  </h4>
                  <dl class="space-y-2">
                    <div>
                      <dt class="text-xs text-gray-500">Plaid Account ID</dt>
                      <dd class="mt-1 flex items-center justify-between">
                        <span class="text-sm font-mono text-gray-900 truncate">{{ plaidAccount.plaid_account_id || 'N/A' }}</span>
                        <button 
                          v-if="plaidAccount.plaid_account_id"
                          @click="copyToClipboard(plaidAccount.plaid_account_id, 'Account ID')"
                          class="ml-2 text-gray-400 hover:text-gray-600"
                          title="Copy to clipboard"
                        >
                          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184" />
                          </svg>
                        </button>
                      </dd>
                    </div>
                    <div>
                      <dt class="text-xs text-gray-500">Account Mask</dt>
                      <dd class="mt-1 text-sm text-gray-900">{{ plaidAccount.account_mask ? `****${plaidAccount.account_mask}` : 'N/A' }}</dd>
                    </div>
                    <div>
                      <dt class="text-xs text-gray-500">Account Type</dt>
                      <dd class="mt-1 text-sm text-gray-900">{{ formatAccountType(plaidAccount.account_type, plaidAccount.account_subtype) }}</dd>
                    </div>
                  </dl>
                </div>

                <!-- Balance Information Card -->
                <div class="bg-white border border-gray-200 rounded-lg p-4 md:col-span-2">
                  <h4 class="text-sm font-semibold text-gray-900 mb-3 flex items-center">
                    <svg class="h-4 w-4 mr-2 text-gray-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    Balance Information
                  </h4>
                  <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                      <dt class="text-xs text-gray-500">Current Balance</dt>
                      <dd class="mt-1 text-lg font-semibold text-gray-900">{{ formatCurrency(plaidAccount.current_balance_cents) }}</dd>
                    </div>
                    <div v-if="plaidAccount.available_balance_cents !== null && plaidAccount.available_balance_cents !== plaidAccount.current_balance_cents">
                      <dt class="text-xs text-gray-500">Available Balance</dt>
                      <dd class="mt-1 text-lg font-semibold text-gray-900">{{ formatCurrency(plaidAccount.available_balance_cents) }}</dd>
                    </div>
                    <div>
                      <dt class="text-xs text-gray-500">Balance Last Updated</dt>
                      <dd class="mt-1 text-sm text-gray-900">{{ formatDateTime(plaidAccount.balance_updated_at) }}</dd>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Connect Button -->
            <div v-if="!isLinked" class="text-center py-8">
              <!-- Show special message if adding to existing connection -->
              <div v-if="hasExistingConnection" class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <p class="text-sm text-blue-800 font-medium mb-2">
                  Adding to Existing Connection
                </p>
                <p class="text-sm text-blue-700">
                  You already have accounts connected to <strong>{{ existingConnectionInstitution }}</strong>.
                  You can add more accounts from the same institution using your existing connection.
                </p>
              </div>

              <p class="text-sm text-gray-600 mb-6">
                <template v-if="hasExistingConnection">
                  Select additional accounts to link to your existing {{ existingConnectionInstitution }} connection.
                </template>
                <template v-else>
                  Connect this account to your bank to automatically import transactions and update balances.
                </template>
                <br>
                Your banking credentials are never stored on our servers.
              </p>
              <button
                @click="openPlaidLink"
                class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-sm text-white uppercase tracking-widest hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
              >
                {{ hasExistingConnection ? 'Add More Accounts' : 'Connect to Bank' }}
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
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PlaidSyncTimestamp from '@/Components/PlaidSyncTimestamp.vue';
import { formatCurrency } from '@/utils/format.js';
import { useToast } from '@/composables/useToast';

// Initialize toast
const toast = useToast();

// Props
const props = defineProps({
  budget: Object,
  account: Object,
  linkToken: String,
  isLinked: Boolean,
  plaidAccount: Object,
  connectionMetadata: Object,
  hasExistingConnection: Boolean,
  existingConnectionInstitution: String,
});

// Form refs
const plaidForm = ref(null);
const publicTokenInput = ref(null);
const metadataInput = ref(null);

// State
const syncInProgress = ref(false);
const balanceUpdateInProgress = ref(false);
const updateConnectionInProgress = ref(false);
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
        // Handle specific error cases
        if (err.error_type === 'INSTITUTION_ERROR' && err.error_code === 'INSTITUTION_REGISTRATION_REQUIRED') {
          toast.error(`Institution Registration Required: ${err.display_message || 'This institution requires special registration. Please contact support for assistance connecting this account.'}`);
        } else if (err.error_message) {
          toast.error(`Connection Error: ${err.error_message}`);
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

const syncTransactions = () => {
  syncInProgress.value = true;
  router.post(
    route('plaid.sync', [props.budget.id, props.account.id]),
    {},
    {
      onSuccess: (page) => {
        // Show success or error message if available
        if (page.props.flash && page.props.flash.message) {
          toast.success(page.props.flash.message);
        } else if (page.props.flash && page.props.flash.error) {
          toast.error(page.props.flash.error);
        } else if (page.props.error) {
          toast.error(page.props.error);
        }
      },
      onError: (errors) => {
        if (errors.message) {
          toast.error(errors.message);
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
          toast.success(page.props.flash.message);
        } else if (page.props.flash && page.props.flash.error) {
          toast.error(page.props.flash.error);
        }
      },
      onError: (errors) => {
        // Show error message if available
        if (errors.message) {
          toast.error(errors.message);
        }
      },
      onFinish: () => {
        balanceUpdateInProgress.value = false;
      }
    }
  );
};

const updateConnection = async () => {
  updateConnectionInProgress.value = true;
  
  try {
    // Fetch the upgrade link token from the API using axios (already configured with CSRF token)
    const response = await axios.post(route('plaid.upgrade-link-token', [props.budget.id, props.account.id]));
    const data = response.data;
    
    if (!data.link_token) {
      toast.error(data.error || 'Failed to get re-authentication link');
      return;
    }
    
    // Create a new Plaid Link handler with the update token
    const updateHandler = window.Plaid.create({
      token: data.link_token,
      onSuccess: (public_token, metadata) => {
        toast.success('Connection re-authenticated successfully!');
        // Reload the page to refresh the connection status
        router.reload();
      },
      onExit: (err, metadata) => {
        if (err) {
          toast.error(`Re-authentication failed: ${err.error_message || 'Unknown error'}`);
        }
      },
    });
    
    // Open Plaid Link in update mode
    updateHandler.open();
    
  } catch (error) {
    const message = error.response?.data?.error || error.message || 'Unknown error';
    toast.error('Failed to start re-authentication: ' + message);
  } finally {
    updateConnectionInProgress.value = false;
  }
};

const confirmDisconnect = async () => {
  const confirmed = await toast.confirm({
    title: 'Disconnect from Plaid',
    message: 'Are you sure you want to disconnect from Plaid? This will not delete any imported transactions.',
    confirmText: 'Disconnect',
    cancelText: 'Cancel',
    type: 'warning'
  });
  
  if (confirmed) {
    router.delete(route('plaid.destroy', [props.budget.id, props.account.id]));
  }
};

// Helper functions for metadata display
const formatDateTime = (dateString) => {
  if (!dateString) return 'N/A';
  const date = new Date(dateString);
  return new Intl.DateTimeFormat('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  }).format(date);
};

const formatDate = (dateString) => {
  if (!dateString) return 'N/A';
  const date = new Date(dateString);
  return new Intl.DateTimeFormat('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric'
  }).format(date);
};

const copyToClipboard = async (text, label) => {
  try {
    await navigator.clipboard.writeText(text);
    toast.success(`${label} copied to clipboard`);
  } catch (err) {
    toast.error('Failed to copy to clipboard');
  }
};

const getStatusColor = (status) => {
  const colors = {
    'active': 'bg-green-100 text-green-800 border-green-200',
    'error': 'bg-red-100 text-red-800 border-red-200',
    'expired': 'bg-amber-100 text-amber-800 border-amber-200',
    'disconnected': 'bg-gray-100 text-gray-800 border-gray-200'
  };
  return colors[status] || colors.disconnected;
};

const getStatusLabel = (status) => {
  const labels = {
    'active': 'Active',
    'error': 'Error',
    'expired': 'Expired',
    'disconnected': 'Disconnected'
  };
  return labels[status] || status;
};

const formatAccountType = (type, subtype) => {
  if (!type) return 'N/A';
  const typeFormatted = type.charAt(0).toUpperCase() + type.slice(1);
  if (!subtype) return typeFormatted;
  const subtypeFormatted = subtype.charAt(0).toUpperCase() + subtype.slice(1);
  return `${typeFormatted} / ${subtypeFormatted}`;
};
</script> 