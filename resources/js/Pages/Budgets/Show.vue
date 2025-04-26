<template>
  <Head :title="budget.name" />

  <AuthenticatedLayout>
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ budget.name }}</h2>
    </template>

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="flex flex-col md:flex-row gap-6">
          <!-- Left Sidebar with Accounts -->
          <div class="md:w-1/3">
            <div class="bg-white rounded-lg shadow-sm p-5 mb-6">
              <div class="flex justify-between items-center mb-3">
                <div class="font-medium text-gray-900">{{ budget.description }}</div>
                <Link :href="route('budgets.edit', budget.id)" class="text-gray-400 hover:text-gray-600">
                  <PencilIcon class="w-5 h-5" />
                </Link>
              </div>
            </div>

            <!-- Accounts Overview -->
            <div class="bg-white rounded-lg shadow-sm p-5">
              <div class="flex justify-between items-center mb-6">
                <h3 class="font-semibold text-lg">Accounts</h3>
                <div class="flex space-x-2">
                  <Link 
                    :href="route('budget.account.create', budget.id)" 
                    class="px-3 py-1.5 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 flex items-center"
                  >
                    <PlusIcon class="w-4 h-4 mr-1" />
                    Manual
                  </Link>
                  <Link 
                    :href="route('plaid.connect', budget.id)" 
                    class="px-3 py-1.5 bg-white border border-green-300 rounded-md text-sm font-medium text-green-700 hover:bg-green-50 flex items-center"
                  >
                    <BanknotesIcon class="w-4 h-4 mr-1" />
                    Bank
                  </Link>
                </div>
              </div>

              <!-- Cash & Bank Accounts Section -->
              <div v-if="cashAccounts.length > 0" class="mb-6">
                <h4 class="text-xs uppercase font-semibold text-gray-500 mb-3">Cash & Bank</h4>

                <div v-for="account in cashAccounts" :key="account.id" class="mb-3 p-3 rounded-lg hover:bg-gray-50">
                  <div class="flex justify-between items-start">
                    <div>
                      <div class="font-medium flex items-center">
                        {{ account.name }}
                        <Link :href="route('budget.account.edit', [budget.id, account.id])" class="text-gray-400 hover:text-gray-600 ml-1">
                          <PencilIcon class="w-4 h-4" />
                        </Link>
                      </div>
                      <div class="text-sm text-gray-500">
                        {{ account.type.charAt(0).toUpperCase() + account.type.slice(1) }}
                        <template v-if="account.plaidAccount">
                          · {{ account.plaidAccount.institution_name }}
                        </template>
                      </div>
                    </div>
                    <div class="text-right">
                      <div :class="account.current_balance_cents >= 0 ? 'text-green-600' : 'text-red-600'" class="font-bold">
                        ${{ formatAmount(account.current_balance_cents / 100) }}
                      </div>
                      <div v-if="account.plaidAccount && account.plaidAccount.balance_updated_at" class="text-xs text-gray-500">
                        as of {{ formatDate(account.plaidAccount.balance_updated_at) }}
                      </div>
                      <div v-else-if="account.balance_updated_at" class="text-xs text-gray-500">
                        as of {{ formatDate(account.balance_updated_at) }}
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Credit Cards Section -->
              <div v-if="creditAccounts.length > 0" class="mb-6">
                <h4 class="text-xs uppercase font-semibold text-gray-500 mb-3">Credit Cards</h4>

                <div v-for="account in creditAccounts" :key="account.id" class="mb-3 p-3 rounded-lg hover:bg-gray-50">
                  <div class="flex justify-between items-start">
                    <div>
                      <div class="font-medium flex items-center">
                        {{ account.name }}
                        <Link :href="route('budget.account.edit', [budget.id, account.id])" class="text-gray-400 hover:text-gray-600 ml-1">
                          <PencilIcon class="w-4 h-4" />
                        </Link>
                      </div>
                      <div class="text-sm text-gray-500">
                        Credit Card
                        <template v-if="account.plaidAccount">
                          · {{ account.plaidAccount.institution_name }}
                        </template>
                      </div>
                    </div>
                    <div class="text-right">
                      <div :class="account.current_balance_cents <= 0 ? 'text-green-600' : 'text-red-600'" class="font-bold">
                        ${{ formatAmount(Math.abs(account.current_balance_cents / 100)) }}
                      </div>
                      <div v-if="account.plaidAccount && account.plaidAccount.balance_updated_at" class="text-xs text-gray-500">
                        as of {{ formatDate(account.plaidAccount.balance_updated_at) }}
                      </div>
                      <div v-else-if="account.balance_updated_at" class="text-xs text-gray-500">
                        as of {{ formatDate(account.balance_updated_at) }}
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Other Accounts Section -->
              <div v-if="otherAccounts.length > 0" class="mb-6">
                <h4 class="text-xs uppercase font-semibold text-gray-500 mb-3">Other</h4>

                <div v-for="account in otherAccounts" :key="account.id" class="mb-3 p-3 rounded-lg hover:bg-gray-50">
                  <div class="flex justify-between items-start">
                    <div>
                      <div class="font-medium flex items-center">
                        {{ account.name }}
                        <Link :href="route('budget.account.edit', [budget.id, account.id])" class="text-gray-400 hover:text-gray-600 ml-1">
                          <PencilIcon class="w-4 h-4" />
                        </Link>
                      </div>
                      <div class="text-sm text-gray-500">
                        {{ account.type.charAt(0).toUpperCase() + account.type.slice(1) }}
                        <template v-if="account.plaidAccount">
                          · {{ account.plaidAccount.institution_name }}
                        </template>
                      </div>
                    </div>
                    <div class="text-right">
                      <div :class="account.current_balance_cents >= 0 ? 'text-green-600' : 'text-red-600'" class="font-bold">
                        ${{ formatAmount(Math.abs(account.current_balance_cents / 100)) }}
                      </div>
                      <div v-if="account.plaidAccount && account.plaidAccount.balance_updated_at" class="text-xs text-gray-500">
                        as of {{ formatDate(account.plaidAccount.balance_updated_at) }}
                      </div>
                      <div v-else-if="account.balance_updated_at" class="text-xs text-gray-500">
                        as of {{ formatDate(account.balance_updated_at) }}
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Net Worth -->
              <div class="pt-4 border-t">
                <div class="flex justify-between items-center">
                  <div class="text-gray-600 font-medium">Net Worth</div>
                  <div :class="netWorth >= 0 ? 'text-green-600' : 'text-red-600'" class="text-xl font-bold">
                    ${{ formatAmount(Math.abs(netWorth)) }}
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Main Content Area -->
          <div class="md:w-2/3">
            <!-- Header -->
            <div class="flex justify-between items-center mb-4">
              <div class="flex space-x-2">
                <Link 
                  :href="route('budget.statistics.monthly', budget.id)" 
                  class="px-3 py-1.5 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 flex items-center"
                >
                  <ChartBarIcon class="w-4 h-4 mr-1" />
                  Monthly Statistics
                </Link>
                <Link 
                  :href="route('budget.statistics.yearly', budget.id)" 
                  class="px-3 py-1.5 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 flex items-center"
                >
                  <ChartBarSquareIcon class="w-4 h-4 mr-1" />
                  Yearly Statistics
                </Link>
                <Link 
                  :href="route('budget.recurring-transactions.index', budget.id)" 
                  class="px-3 py-1.5 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 flex items-center"
                >
                  <ArrowPathIcon class="w-4 h-4 mr-1" />
                  Recurring Transactions
                </Link>
              </div>

              <div class="flex items-center space-x-2">
                <div class="flex items-center border rounded-md overflow-hidden">
                  <button 
                    @click="viewMode = 'table'" 
                    class="px-3 py-1.5 text-sm font-medium flex items-center" 
                    :class="viewMode === 'table' ? 'bg-gray-100 text-gray-800' : 'bg-white text-gray-600 hover:bg-gray-50'"
                  >
                    <TableCellsIcon class="w-4 h-4 mr-1" />
                    Table
                  </button>
                  <button 
                    @click="viewMode = 'chart'" 
                    class="px-3 py-1.5 text-sm font-medium flex items-center" 
                    :class="viewMode === 'chart' ? 'bg-gray-100 text-gray-800' : 'bg-white text-gray-600 hover:bg-gray-50'"
                  >
                    <ChartLineIcon class="w-4 h-4 mr-1" />
                    Chart
                  </button>
                </div>
                <Link 
                  :href="route('budget.transaction.create', budget.id)" 
                  class="px-3 py-1.5 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 flex items-center"
                >
                  <PlusIcon class="w-4 h-4 mr-1" />
                  Add Transaction
                </Link>
              </div>
            </div>

            <!-- Filter Panel -->
            <div class="bg-white rounded-lg shadow-sm p-4 mb-4">
              <form @submit.prevent="applyFilters" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">Date Range</label>
                  <select v-model="filters.date_range" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                    <option value="7">Last 7 days</option>
                    <option value="30">Last 30 days</option>
                    <option value="90">Last 90 days</option>
                    <option value="custom">Custom</option>
                  </select>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">Project Months</label>
                  <select v-model="filters.project_months" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                    <option value="1">1 Month</option>
                    <option value="2">2 Months</option>
                    <option value="3">3 Months</option>
                    <option value="6">6 Months</option>
                    <option value="12">1 Year</option>
                  </select>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                  <select v-model="filters.type" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                    <option value="">All Types</option>
                    <option value="income">Income</option>
                    <option value="expense">Expenses</option>
                    <option value="recurring">Recurring</option>
                  </select>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                  <input 
                    type="text" 
                    v-model="filters.search" 
                    placeholder="Search transactions..." 
                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                  >
                </div>
              </form>
            </div>

            <!-- Account Tabs -->
            <div class="bg-white rounded-t-lg shadow-sm mb-0">
              <div class="border-b">
                <nav class="flex overflow-x-auto">
                  <button 
                    v-for="account in accounts" 
                    :key="account.id"
                    @click="selectedAccountId = account.id"
                    class="px-4 py-3 inline-flex items-center text-sm font-medium whitespace-nowrap"
                    :class="selectedAccountId === account.id ? 'border-b-2 border-blue-500 text-blue-600' : 'text-gray-600 hover:text-gray-800 hover:border-gray-300 hover:border-b-2'"
                  >
                    {{ account.name }}
                    <span 
                      :class="account.current_balance_cents >= 0 ? 'text-green-600' : 'text-red-600'" 
                      class="ml-2 font-medium"
                    >
                      ${{ formatAmount(Math.abs(account.current_balance_cents / 100)) }}
                    </span>
                  </button>
                </nav>
              </div>
            </div>

            <!-- Content Container -->
            <div class="bg-white rounded-b-lg shadow-sm border-t-0 border p-4">
              <!-- Table View -->
              <div v-if="viewMode === 'table'" class="overflow-x-auto">
                <table v-if="filteredTransactions.length > 0" class="min-w-full divide-y divide-gray-200">
                  <thead class="bg-gray-50">
                    <tr>
                      <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                      <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                      <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                      <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                      <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Running Balance</th>
                      <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"></th>
                    </tr>
                  </thead>
                  <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="transaction in filteredTransactions" :key="transaction.id" class="hover:bg-gray-50">
                      <td class="px-4 py-3 whitespace-nowrap">
                        {{ formatDateShort(transaction.date) }}
                        <span v-if="transaction.plaidTransaction && transaction.plaidTransaction.pending" class="ml-1 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                          Pending
                        </span>
                      </td>
                      <td class="px-4 py-3">
                        <div class="flex items-center">
                          <span v-if="transaction.plaidTransaction && transaction.plaidTransaction.logo_url" class="flex-shrink-0 h-6 w-6 mr-2">
                            <img :src="transaction.plaidTransaction.logo_url" class="h-6 w-6 rounded-full" :alt="transaction.description">
                          </span>
                          {{ transaction.description }}
                          <span v-if="transaction.recurring_transaction_template_id" class="ml-1 text-gray-400">
                            <ArrowPathIcon class="w-4 h-4" />
                          </span>
                        </div>
                      </td>
                      <td class="px-4 py-3 whitespace-nowrap">
                        <span v-if="transaction.category" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800">
                          {{ transaction.category }}
                        </span>
                      </td>
                      <td class="px-4 py-3 text-right whitespace-nowrap">
                        <span :class="transaction.amount_in_cents > 0 ? 'text-green-600' : 'text-red-600'" class="font-medium">
                          ${{ formatAmount(Math.abs(transaction.amount_in_cents / 100)) }}
                        </span>
                      </td>
                      <td class="px-4 py-3 text-right whitespace-nowrap">
                        <span :class="transaction.running_balance >= 0 ? 'text-green-600' : 'text-red-600'" class="font-medium">
                          ${{ formatAmount(Math.abs(transaction.running_balance / 100)) }}
                        </span>
                      </td>
                      <td class="px-4 py-3 text-right whitespace-nowrap">
                        <Link 
                          v-if="!transaction.is_projected" 
                          :href="route('budget.transaction.edit', [budget.id, transaction.id])" 
                          class="text-gray-400 hover:text-gray-600"
                        >
                          <PencilIcon class="w-5 h-5" />
                        </Link>
                        <Link 
                          v-else-if="transaction.recurring_transaction_template_id" 
                          :href="route('budget.recurring-transactions.edit', [budget.id, transaction.recurring_transaction_template_id])" 
                          class="text-gray-400 hover:text-gray-600"
                        >
                          <PencilIcon class="w-5 h-5" />
                        </Link>
                      </td>
                    </tr>
                  </tbody>
                </table>
                <div v-else class="text-center py-10 text-gray-500">
                  <DocumentMagnifyingGlassIcon class="w-10 h-10 mx-auto mb-2" />
                  <p>No transactions found</p>
                </div>
              </div>

              <!-- Chart View -->
              <div v-else-if="viewMode === 'chart'" class="h-80">
                <div class="text-center py-8 text-gray-500">
                  <ChartLineIcon class="w-10 h-10 mx-auto mb-2" />
                  <p>Balance projection chart coming soon</p>
                </div>
              </div>

              <!-- Pagination -->
              <div v-if="filteredTransactions.length > 0" class="mt-4 flex justify-center">
                <Pagination class="mt-4" :links="transactions.links" />
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { 
  PencilIcon, 
  PlusIcon, 
  BanknotesIcon, 
  ChartBarIcon, 
  ChartBarSquareIcon, 
  ArrowPathIcon,
  ChartLineIcon,
  TableCellsIcon,
  DocumentMagnifyingGlassIcon
} from '@heroicons/vue/24/outline';

// Define props
const props = defineProps({
  budget: Object,
  transactions: Object,
  remainingAmount: Number,
  percentUsed: Number
});

// Reactive state
const viewMode = ref('table');
const selectedAccountId = ref(props.budget.accounts?.length > 0 ? props.budget.accounts[0].id : null);
const filters = ref({
  date_range: '90',
  project_months: '3',
  type: '',
  search: ''
});

// Format a monetary amount with 2 decimal places
const formatAmount = (amount) => {
  return parseFloat(amount).toFixed(2);
};

// Format a date as MM/DD/YYYY
const formatDate = (dateString) => {
  const date = new Date(dateString);
  const month = date.getMonth() + 1;
  const day = date.getDate();
  const year = date.getFullYear();
  const hours = date.getHours();
  const minutes = date.getMinutes();
  const ampm = hours >= 12 ? 'PM' : 'AM';
  const hours12 = hours % 12 || 12;
  return `${month}/${day}/${year}, ${hours12}:${minutes.toString().padStart(2, '0')} ${ampm}`;
};

// Format a date as MMM D, YYYY
const formatDateShort = (dateString) => {
  const date = new Date(dateString);
  const options = { month: 'short', day: 'numeric', year: 'numeric' };
  return date.toLocaleDateString('en-US', options);
};

// Apply filters (to be implemented with actual API calls)
const applyFilters = () => {
  // This would be implemented with actual API calls to filter transactions
  console.log('Applying filters:', filters.value);
};

// Filter accounts by type
const accounts = computed(() => props.budget.accounts || []);

const cashAccounts = computed(() => 
  accounts.value.filter(account => account.type === 'checking' || account.type === 'savings')
);

const creditAccounts = computed(() => 
  accounts.value.filter(account => account.type === 'credit')
);

const otherAccounts = computed(() => 
  accounts.value.filter(account => account.type !== 'checking' && account.type !== 'savings' && account.type !== 'credit')
);

// Calculate net worth
const netWorth = computed(() => {
  if (!accounts.value.length) return 0;
  return accounts.value.reduce((sum, account) => sum + account.current_balance_cents, 0) / 100;
});

// Filter transactions by selected account
const filteredTransactions = computed(() => {
  if (!props.transactions || !props.transactions.data) return [];
  return props.transactions.data.filter(transaction => 
    transaction.account_id === selectedAccountId.value
  );
});
</script> 