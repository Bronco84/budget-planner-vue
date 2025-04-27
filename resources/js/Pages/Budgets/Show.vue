<template>
  <Head :title="budget.name" />

  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ budget.name }}</h2>
        <Link 
          :href="route('budgets.edit', budget.id)" 
          class="px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700"
        >
          Edit Budget
        </Link>
      </div>
    </template>

    <div class="py-12">
      <div class="max-w-full mx-auto sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
          <!-- Left sidebar with Budget Overview and Accounts -->
          <div class="lg:col-span-1">
            <!-- Budget Overview Card -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
              <div class="p-4">
                <h3 class="text-lg font-medium text-gray-900 mb-3">Budget Overview</h3>
                
                <div class="space-y-3">
                  <div class="bg-gray-50 p-3 rounded-lg">
                    <div class="text-sm font-medium text-gray-500">Total Balance</div>
                    <div class="text-xl font-semibold mt-1">${{ totalBalance.toFixed(2) }}</div>
                  </div>
                  
                  <div class="bg-gray-50 p-3 rounded-lg">
                    <div class="text-sm font-medium text-gray-500">Description</div>
                    <div class="text-sm mt-1">{{ budget.description || 'No description provided' }}</div>
                  </div>
                </div>
              </div>
            </div>
            
            <!-- Projection Controls Card -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
              <div class="p-4">
                <h3 class="text-lg font-medium text-gray-900 mb-3">Future Projections</h3>
                
                <div class="space-y-3">
                  <div>
                    <label for="projection_months" class="block text-sm font-medium text-gray-700">
                      Project Future Transactions
                    </label>
                    <select
                      id="projection_months"
                      v-model="projectionForm.months"
                      @change="updateProjections"
                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                    >
                      <option value="0">No projections</option>
                      <option value="1">1 month</option>
                      <option value="3">3 months</option>
                      <option value="6">6 months</option>
                      <option value="12">12 months</option>
                    </select>
                  </div>

                  <div v-if="projectionForm.months > 0" class="space-y-3">
                    <div>
                      <label for="projection_start_date" class="block text-sm font-medium text-gray-700">Start Date</label>
                      <input 
                        type="date" 
                        id="projection_start_date" 
                        v-model="projectionForm.startDate"
                        @change="updateProjections"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                      >
                    </div>
                    
                    <div v-if="displayedProjectedTransactions.length > 0" class="mt-2 text-sm text-blue-600">
                      Showing {{ displayedProjectedTransactions.length }} projected transaction{{ displayedProjectedTransactions.length === 1 ? '' : 's' }}
                    </div>
                  </div>
                </div>
              </div>
            </div>
            
            <!-- Accounts Card -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
              <div class="p-4">
                <div class="flex justify-between items-center mb-3">
                  <h3 class="text-lg font-medium text-gray-900">Accounts</h3>
                  <Link 
                    :href="route('budgets.accounts.create', budget.id)" 
                    class="inline-flex items-center px-3 py-1 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500"
                  >
                    Add
                  </Link>
                </div>
                
                <div class="space-y-3" v-if="accounts.length > 0">
                  <div 
                    v-for="account in accounts" 
                    :key="account.id" 
                    class="bg-gray-50 p-3 rounded-lg border-l-4"
                    :class="account.current_balance_cents >= 0 ? 'border-green-500' : 'border-red-500'"
                  >
                    <div class="flex justify-between items-start">
                      <div>
                        <div class="font-medium text-gray-900">{{ account.name }}</div>
                        <div class="text-xs text-gray-500 capitalize mt-1">{{ account.type }}</div>
                        <div v-if="account.plaid_account" class="text-xs text-blue-600 mt-1 flex items-center">
                          <span class="w-2 h-2 rounded-full mr-2" 
                                :class="getLastSyncClass(account.plaid_account)"></span>
                          {{ account.plaid_account.last_sync_at ? `Last synced ${formatTimeAgo(account.plaid_account.last_sync_at)}` : 'Not synced yet' }}
                        </div>
                      </div>
                      <div class="text-sm font-medium" :class="account.current_balance_cents >= 0 ? 'text-green-600' : 'text-red-600'">
                        ${{ (account.current_balance_cents / 100).toFixed(2) }}
                      </div>
                    </div>
                    <div class="flex items-center justify-between mt-2">
                      <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full" 
                        :class="account.status_classes">
                        {{ account.status_label }}
                      </span>
                      <div class="flex space-x-2">
                        <Link 
                          v-if="account.plaid_account"
                          :href="route('plaid.link', [budget.id, account.id])" 
                          class="text-xs text-blue-600 hover:text-blue-900"
                        >
                          Bank Sync
                        </Link>
                        <Link 
                          :href="route('budgets.accounts.edit', [budget.id, account.id])" 
                          class="text-xs text-indigo-600 hover:text-indigo-900"
                        >
                          Edit
                        </Link>
                      </div>
                    </div>
                  </div>
                </div>
                
                <div v-else class="bg-gray-50 p-3 text-center rounded-lg">
                  <p class="text-sm text-gray-500">No accounts found.</p>
                </div>
              </div>
            </div>
            
            <!-- Quick Links Card -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mt-6">
              <div class="p-4">
                <h3 class="text-lg font-medium text-gray-900 mb-3">Quick Links</h3>
                <div class="space-y-2">
                  <Link 
                    :href="route('recurring-transactions.index', budget.id)"
                    class="w-full block px-4 py-2 text-sm text-left text-gray-700 hover:bg-gray-100 rounded-md"
                  >
                    Manage Recurring Transactions
                  </Link>
                  <Link 
                    :href="route('budget.projections', budget.id)"
                    class="w-full block px-4 py-2 text-sm text-left text-gray-700 hover:bg-gray-100 rounded-md"
                  >
                    View Detailed Projections
                  </Link>
                </div>
              </div>
            </div>
          </div>
          
          <!-- Main Content Area - Transactions -->
          <div class="lg:col-span-3">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
              <div class="p-6">
                <div class="flex justify-between items-center mb-4">
                  <h3 class="text-lg font-medium text-gray-900">Transactions</h3>
                  <div class="flex space-x-2">
                    <button
                      v-if="hasPlaidAccounts"
                      @click="importFromBank"
                      class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-500"
                      :disabled="syncingTransactions"
                    >
                      <svg v-if="syncingTransactions" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                      </svg>
                      {{ syncingTransactions ? 'Importing...' : 'Import from Bank' }}
                    </button>

                    <!-- Separate button for connecting to bank when no connections exist -->
                    <Link 
                      v-else
                      :href="accounts.length > 0 
                        ? route('budgets.accounts.edit', [budget.id, accounts[0].id]) 
                        : route('budgets.accounts.create', budget.id)"
                      class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-500"
                    >
                      Connect to Bank
                    </Link>

                    <Link 
                      :href="route('budget.transaction.create', budget.id)" 
                      class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500"
                    >
                      Add Transaction
                    </Link>
                  </div>
                </div>
                
                <!-- Search and Filter Controls -->
                <form @submit.prevent="filter">
                  <div class="mb-4 flex flex-col sm:flex-row sm:items-center gap-3">
                    <div class="relative rounded-md shadow-sm flex-grow">
                      <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                        <span class="text-gray-500 sm:text-sm">
                          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd" />
                          </svg>
                        </span>
                      </div>
                      <input 
                        type="text" 
                        v-model="form.search"
                        class="pl-10 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm" 
                        placeholder="Search transactions..."
                      >
                    </div>
                    <select v-model="form.category" class="block rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                      <option value="">All Categories</option>
                      <option v-for="category in categories" :key="category" :value="category">
                        {{ category }}
                      </option>
                    </select>
                    <select v-model="form.timeframe" class="block rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                      <option value="">All Time</option>
                      <option value="this_month">This Month</option>
                      <option value="last_month">Last Month</option>
                      <option value="last_3_months">Last 3 Months</option>
                      <option value="this_year">This Year</option>
                    </select>
                    <button type="submit" class="hidden">Filter</button>
                  </div>
                </form>
                
                <!-- Transactions Table -->
                <div class="border rounded-lg overflow-hidden">
                  <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                      <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-36">Date</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-64">Description</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-48">Category</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-48">Account</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-36">Amount</th>
                        <th scope="col" class="relative px-6 py-3 w-24">
                          <span class="sr-only">Actions</span>
                        </th>
                      </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                      <!-- Real transactions -->
                      <tr v-for="transaction in transactions.data" :key="'actual-' + transaction.id">
                        <td class="px-6 py-4 whitespace-nowrap">
                          <div class="text-sm text-gray-900">{{ formatDate(transaction.date) }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <div class="text-sm font-medium text-gray-900">{{ transaction.description }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <div class="text-sm text-gray-900">{{ transaction.category }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <div class="text-sm text-gray-900">{{ transaction.account?.name || 'N/A' }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <div class="text-sm font-medium" :class="transaction.amount_in_cents >= 0 ? 'text-green-600' : 'text-red-600'">
                            ${{ (transaction.amount_in_cents / 100).toFixed(2) }}
                          </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                          <Link 
                            :href="route('budget.transaction.edit', [budget.id, transaction.id])" 
                            class="text-indigo-600 hover:text-indigo-900"
                          >
                            Edit
                          </Link>
                        </td>
                      </tr>

                      <!-- Projected transactions -->
                      <tr v-for="(transaction, index) in displayedProjectedTransactions" 
                          :key="'projected-' + index"
                          class="bg-blue-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                          <div class="text-sm text-gray-900">{{ formatDate(transaction.date) }}</div>
                          <div class="text-xs text-blue-600 font-medium">Projected</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <div class="text-sm font-medium text-gray-900">{{ transaction.description }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <div class="text-sm text-gray-900">{{ transaction.category }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <div class="text-sm text-gray-900">{{ transaction.account?.name || 'N/A' }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <div class="text-sm font-medium" :class="transaction.amount_in_cents >= 0 ? 'text-green-600' : 'text-red-600'">
                            ${{ (transaction.amount_in_cents / 100).toFixed(2) }}
                          </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                          <Link 
                            v-if="transaction.recurring_transaction_template_id"
                            :href="route('recurring-transactions.edit', [budget.id, transaction.recurring_transaction_template_id])" 
                            class="text-indigo-600 hover:text-indigo-900"
                          >
                            Edit Template
                          </Link>
                          <span v-else class="text-gray-400">Projected</span>
                        </td>
                      </tr>
                      
                      <!-- Empty state -->
                      <tr v-if="transactions.data.length === 0 && (!projectedTransactions || projectedTransactions.length === 0)">
                        <td colspan="6" class="px-6 py-12 text-center text-sm text-gray-500">
                          <p>No transactions found.</p>
                          <p class="mt-1">Add a transaction to get started tracking your finances.</p>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                
                <!-- Pagination -->
                <div v-if="transactions.data.length > 0" class="mt-4 flex items-center justify-between">
                  <div class="flex-1 flex justify-between sm:hidden">
                    <Link
                      v-if="transactions.prev_page_url"
                      :href="transactions.prev_page_url"
                      preserve-scroll
                      class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
                    >
                      Previous
                    </Link>
                    <span v-else class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-300 bg-white cursor-not-allowed">
                      Previous
                    </span>
                    
                    <Link
                      v-if="transactions.next_page_url"
                      :href="transactions.next_page_url"
                      preserve-scroll
                      class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
                    >
                      Next
                    </Link>
                    <span v-else class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-300 bg-white cursor-not-allowed">
                      Next
                    </span>
                  </div>
                  <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                    <div>
                      <p class="text-sm text-gray-700">
                        Showing
                        <span class="font-medium">{{ transactions.from }}</span>
                        to
                        <span class="font-medium">{{ transactions.to }}</span>
                        of
                        <span class="font-medium">{{ transactions.total }}</span>
                        results
                      </p>
                    </div>
                    <div>
                      <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                        <Link
                          v-if="transactions.prev_page_url"
                          :href="transactions.prev_page_url"
                          preserve-scroll
                          class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50"
                        >
                          <span class="sr-only">Previous</span>
                          <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                          </svg>
                        </Link>
                        <span v-else class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-300 cursor-not-allowed">
                          <span class="sr-only">Previous</span>
                          <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                          </svg>
                        </span>
                        
                        <!-- Page numbers would go here if needed -->
                        
                        <Link
                          v-if="transactions.next_page_url"
                          :href="transactions.next_page_url"
                          preserve-scroll
                          class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50"
                        >
                          <span class="sr-only">Next</span>
                          <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                          </svg>
                        </Link>
                        <span v-else class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-300 cursor-not-allowed">
                          <span class="sr-only">Next</span>
                          <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                          </svg>
                        </span>
                      </nav>
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
import { reactive, watch, computed, ref, onMounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

// Define props
const props = defineProps({
  budget: Object,
  accounts: Array,
  totalBalance: Number,
  transactions: Object,
  projectedTransactions: Array,
  projectionParams: Object,
  categories: Array,
  filters: Object
});

// Form state for filters
const form = reactive({
  search: props.filters.search || '',
  category: props.filters.category || '',
  timeframe: props.filters.timeframe || ''
});

// Form state for projections
const projectionForm = reactive({
  months: props.projectionParams?.months || 1,
  startDate: props.projectionParams?.startDate || new Date().toISOString().slice(0, 10),
});

// Computed property for displayed projected transactions
const displayedProjectedTransactions = computed(() => {
  if (!props.projectedTransactions || projectionForm.months === 0) return [];
  
  // Filter projected transactions based on the same criteria as actual transactions
  let filtered = [...props.projectedTransactions];
  
  if (form.search) {
    const searchLower = form.search.toLowerCase();
    filtered = filtered.filter(tx => 
      tx.description.toLowerCase().includes(searchLower) || 
      tx.category?.toLowerCase().includes(searchLower)
    );
  }
  
  if (form.category) {
    filtered = filtered.filter(tx => tx.category === form.category);
  }
  
  // Sort by date (newest first)
  return filtered.sort((a, b) => new Date(b.date) - new Date(a.date));
});

// Apply debounced filtering when form values change
watch(form, debounce(() => filter(), 300));

// Filter function
function filter() {
  router.get(
    route('budgets.show', props.budget.id), 
    { 
      search: form.search, 
      category: form.category,
      timeframe: form.timeframe,
      include_projections: projectionForm.months > 0,
      start_date: projectionForm.startDate,
      projection_months: projectionForm.months
    }, 
    { 
      preserveState: true,
      preserveScroll: true,
      replace: true 
    }
  );
}

// Update projections
function updateProjections() {
  router.get(
    route('budgets.show', props.budget.id), 
    { 
      search: form.search, 
      category: form.category,
      timeframe: form.timeframe,
      include_projections: projectionForm.months > 0,
      start_date: projectionForm.startDate,
      projection_months: projectionForm.months
    }, 
    { 
      preserveState: true,
      preserveScroll: true 
    }
  );
}

// Debounce helper
function debounce(fn, delay = 300) {
  let timeout;
  
  return (...args) => {
    clearTimeout(timeout);
    timeout = setTimeout(() => fn(...args), delay);
  };
}

// Helper functions for formatting dates
const formatDate = (dateString) => {
  if (!dateString) return 'N/A';
  const date = new Date(dateString);
  return date.toLocaleDateString();
};

const formatDateTime = (dateTimeString) => {
  if (!dateTimeString) return 'N/A';
  const date = new Date(dateTimeString);
  return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
};

// Format time ago (e.g., "3 minutes ago", "2 hours ago")
const formatTimeAgo = (dateTimeString) => {
  if (!dateTimeString) return 'N/A';
  
  const date = new Date(dateTimeString);
  const now = new Date();
  const diffMs = now - date;
  const diffMins = Math.floor(diffMs / (1000 * 60));
  const diffHours = Math.floor(diffMs / (1000 * 60 * 60));
  const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));
  
  if (diffMins < 60) {
    return `${diffMins} minute${diffMins !== 1 ? 's' : ''} ago`;
  } else if (diffHours < 24) {
    return `${diffHours} hour${diffHours !== 1 ? 's' : ''} ago`;
  } else {
    return `${diffDays} day${diffDays !== 1 ? 's' : ''} ago`;
  }
};

// State for Plaid sync
const syncingTransactions = ref(false);

// Check if any accounts have Plaid connections
const hasPlaidAccounts = computed(() => {
  return props.accounts.some(account => account.plaid_account !== null);
});

// Import transactions from all Plaid-connected accounts
const importFromBank = () => {
  console.log('Import from bank button clicked');
  
  // Get all accounts with Plaid connections
  const plaidAccounts = props.accounts.filter(account => account.plaid_account !== null);
  
  if (plaidAccounts.length === 0) {
    console.error('No Plaid-connected accounts found');
    alert('No Plaid-connected accounts found. Please connect an account to Plaid first.');
    return;
  }
  
  // Check if we've already synced today to avoid unnecessary API costs
  const syncTimes = plaidAccounts
    .map(account => account.plaid_account?.last_sync_at)
    .filter(time => time !== null && time !== undefined);
    
  if (syncTimes.length > 0) {
    const mostRecentSync = new Date(Math.max(...syncTimes.map(time => new Date(time).getTime())));
    const now = new Date();
    
    // Check if the most recent sync was today (same day)
    if (mostRecentSync.getDate() === now.getDate() && 
        mostRecentSync.getMonth() === now.getMonth() && 
        mostRecentSync.getFullYear() === now.getFullYear()) {
      
      const confirmSync = confirm(
        'You have already synced with Plaid today. Each sync uses a Plaid API call that costs money. Are you sure you want to sync again?'
      );
      
      if (!confirmSync) {
        return;
      }
    }
  }
  
  syncingTransactions.value = true;
  
  // Use the sync-all route
  const syncAllUrl = route('plaid.sync-all', props.budget.id);
  console.log('Attempting to sync using URL:', syncAllUrl);
  
  router.post(
    syncAllUrl,
    {},
    { 
      preserveScroll: true,
      onSuccess: (page) => {
        console.log('Sync all operation succeeded, response:', page);
        syncingTransactions.value = false;
        
        // Show success message to user
        if (page.props.flash && page.props.flash.message) {
          console.log('Sync message:', page.props.flash.message);
          alert(page.props.flash.message); // Show an alert for testing
        } else {
          console.log('No flash message in response');
        }
        
        // Reload only the necessary components
        router.reload({ 
          only: ['transactions', 'accounts'],
          preserveScroll: true
        });
      },
      onError: (errors) => {
        console.error('Sync all operation failed:', errors);
        syncingTransactions.value = false;
        
        // Show detailed error information
        let errorMessage = 'Failed to sync transactions. Please try again.';
        
        if (errors.message) {
          errorMessage = errors.message;
        } else if (errors.response && errors.response.status) {
          errorMessage = `Server returned error code ${errors.response.status}`;
        }
        
        console.error('Error details:', errorMessage);
        alert(errorMessage);
      }
    }
  );
};

// Computed property to get connected accounts
const plaidConnectedAccounts = computed(() => {
  return props.accounts.filter(account => account.plaid_account !== null);
});

// Function to get the text about last sync
const getLastSyncText = () => {
  if (!hasPlaidAccounts.value) return '';
  
  const syncTimes = plaidConnectedAccounts.value
    .map(account => account.plaid_account?.last_sync_at)
    .filter(time => time !== null && time !== undefined);
  
  if (syncTimes.length === 0) return 'No sync history';
  
  // Find most recent sync time
  const mostRecentSync = new Date(Math.max(...syncTimes.map(time => new Date(time).getTime())));
  
  // Format the time difference nicely
  const now = new Date();
  const diffMs = now - mostRecentSync;
  const diffMins = Math.floor(diffMs / (1000 * 60));
  const diffHours = Math.floor(diffMs / (1000 * 60 * 60));
  const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));
  
  if (diffMins < 60) {
    return `Last synced ${diffMins} minute${diffMins !== 1 ? 's' : ''} ago`;
  } else if (diffHours < 24) {
    return `Last synced ${diffHours} hour${diffHours !== 1 ? 's' : ''} ago`;
  } else {
    return `Last synced ${diffDays} day${diffDays !== 1 ? 's' : ''} ago`;
  }
};

// Function to get a colored indicator based on how recent the sync is
const getLastSyncClass = (plaidAccount) => {
  if (!plaidAccount || !plaidAccount.last_sync_at) return 'bg-gray-400';
  
  const lastSync = new Date(plaidAccount.last_sync_at);
  const now = new Date();
  const diffHours = (now - lastSync) / (1000 * 60 * 60);
  
  if (diffHours < 6) return 'bg-green-500';
  if (diffHours < 24) return 'bg-yellow-500';
  return 'bg-red-500';
};

// Check if we should auto-sync based on last sync time
onMounted(() => {
  if (hasPlaidAccounts.value) {
    // Get the latest sync time across all accounts
    const syncTimes = plaidConnectedAccounts.value
      .map(account => account.plaid_account?.last_sync_at)
      .filter(time => time !== null && time !== undefined);
    
    if (syncTimes.length > 0) {
      const mostRecentSync = new Date(Math.max(...syncTimes.map(time => new Date(time).getTime())));
      const now = new Date();
      const diffHours = (now - mostRecentSync) / (1000 * 60 * 60);
      
      // Only auto-sync if we haven't synced in the last 12 hours
      if (diffHours > 12) {
        console.log('Auto-syncing because last sync was more than 12 hours ago');
        importFromBank();
      } else {
        console.log('Skipping auto-sync because already synced within the last 12 hours');
      }
    } else {
      // No sync history, do initial sync
      console.log('Auto-syncing because no previous sync history');
      importFromBank();
    }
  }
});
</script> 