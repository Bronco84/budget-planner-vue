<template>
  <Head :title="budget.name" />

  <AuthenticatedLayout>
    <div class="py-6">
      <div class="max-w-full mx-auto px-6">
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
          <!-- Left sidebar with Budget Overview and Accounts -->
          <div class="lg:col-span-1">
            <!-- Budget Overview Card -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
              <div class="p-4">
                  <div class="flex items-center justify-between mb-2">
                  <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ budget.name }}</h2>
                  <Link
                      :href="route('budgets.edit', budget.id)"
                      class="flex items-center text-sm"
                  >
                      Edit Budget <PencilIcon class="ml-2 w-3 h-3" />
                  </Link>
                  </div>
                <div class="space-y-3">
                  <div class="bg-gray-50 p-3 rounded-lg">
                    <div class="text-sm font-medium text-gray-500">Total Balance</div>
                    <div class="text-xl font-semibold mt-1">{{ formatCurrency(totalBalance) }}</div>
                  </div>

                  <div class="bg-gray-50 p-3 rounded-lg" v-if="budget.description">
                    <div class="text-sm font-medium text-gray-500">Description</div>
                    <div class="text-sm mt-1">{{ budget.description || 'No description provided' }}</div>
                  </div>

                  <!-- File Attachments Section -->
                  <div class="bg-gray-50 p-3 rounded-lg">
                    <div class="text-sm font-medium text-gray-500 mb-2">File Attachments</div>
                    <div class="space-y-2">
                      <div v-if="budgetAttachments.length === 0" class="text-xs text-gray-500 text-center py-2">
                        No files attached
                      </div>
                      <div v-else>
                        <FileAttachmentList
                          :attachments="budgetAttachments"
                          @deleted="handleFileDeleted"
                        />
                      </div>
                      <button @click="showFileUploadModal = true" class="w-full mt-2 text-xs text-indigo-600 hover:text-indigo-800 border border-indigo-300 rounded px-2 py-1">
                        Attach File
                      </button>
                    </div>
                  </div>
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
                                <option value="2">2 months</option>
                                <option value="3">3 months</option>
                                <option value="6">6 months</option>
                                <option value="12">12 months</option>
                            </select>
                        </div>

                        <div v-if="projectionForm.months > 0" class="space-y-3">
                            <div v-if="displayedProjectedTransactions.length > 0" class="mt-2 text-sm text-blue-600">
                                Showing {{ displayedProjectedTransactions.length }} projected transaction{{ displayedProjectedTransactions.length === 1 ? '' : 's' }}
                            </div>
                        </div>
                    </div>
                </div>
              </div>
            </div>

            <!-- Accounts Card -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
              <div class="p-4">
                <div class="flex justify-between items-center mb-3">
                  <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Accounts</h3>
                  <div class="flex space-x-2">
                    <Link
                      :href="route('plaid.discover', budget.id)"
                      class="inline-flex items-center px-3 py-1 bg-green-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-500"
                      title="Import accounts from your bank"
                    >
                      <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                      </svg>
                      Import
                    </Link>
                    <Link
                      :href="route('budgets.accounts.create', budget.id)"
                      class="inline-flex items-center px-3 py-1 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500"
                    >
                      Add
                    </Link>
                  </div>
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
                            <div class="whitespace-nowrap">
                                {{ account.plaid_account.last_sync_at ? `Last synced ${formatTimeAgo(account.plaid_account.last_sync_at)}` : 'Not synced yet' }}
                            </div>
                        </div>
                      </div>
                      <div class="text-sm font-medium" :class="account.current_balance_cents >= 0 ? 'text-green-600' : 'text-red-600'">
                        {{ formatCurrency(account.current_balance_cents) }}
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
                          v-if="!account.plaid_account"
                          :href="route('plaid.link', [budget.id, account.id])"
                          class="text-xs text-blue-600 hover:text-blue-900"
                        >
                          Connect to Bank
                        </Link>
                        <Link
                          :href="route('budget.account.projections', [budget.id, account.id])"
                          class="text-xs text-green-600 hover:text-green-900"
                        >
                          Projections
                        </Link>
                        <Link
                          :href="route('budget.account.balance-projection', [budget.id, account.id])"
                          class="text-xs text-purple-600 hover:text-purple-900"
                        >
                          Balance Chart
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
                      :href="route('recurring-transactions.index', budget.id)"
                      class="inline-flex items-center px-4 py-2 bg-green-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-500"
                    >
                      Recurring Transactions
                    </Link>
                    <Link
                      :href="route('budget.transaction.index', budget.id)"
                      class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500"
                    >
                      Regular Transactions
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
                    <select v-model="form.type" class="block rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                      <option value="">All Types</option>
                      <option value="income">Income</option>
                      <option value="expense">Expenses</option>
                      <option value="recurring">Recurring</option>
                    </select>
                    <select v-model="form.category" class="block rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                      <option value="">All Categories</option>
                      <option v-for="category in categories" :key="category" :value="category">
                        {{ category }}
                      </option>
                    </select>
                    <select v-model="form.pending" class="block rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                      <option value="">All Status</option>
                      <option value="false">Posted</option>
                      <option value="true">Pending</option>
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

                <!-- Account Tabs -->
                <div class="mb-4 border-b border-gray-200">
                  <div class="overflow-x-auto overflow-y-hidden">
                    <nav class="flex -mb-px min-w-full whitespace-nowrap">
                      <a
                        v-for="account in accounts"
                        :key="account.id"
                        href="#"
                        @click.prevent="selectAccount(account.id)"
                        class="whitespace-nowrap py-4 px-4 border-b-2 font-medium text-sm"
                        :class="[
                          activeAccountId === parseInt(account.id, 10) ?
                            'border-indigo-500 text-indigo-600' :
                            'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                        ]"
                      >
                        {{ account.name }}
                      </a>
                    </nav>
                  </div>
                </div>

                <!-- Transactions Table -->
                <div class="border rounded-lg overflow-x-auto">
                  <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                      <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-36">Date</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-64">Description</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-48">Category</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-48">Account</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-36">Amount</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-36">Balance</th>
                        <th scope="col" class="relative px-6 py-3 w-24">
                          <span class="sr-only">Actions</span>
                        </th>
                      </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                      <!-- Variable to track if we've shown the today marker -->
                      <template v-for="(transaction, index) in props.transactions.data" :key="transaction.id || ('proj-' + index)">
                        <!-- Today marker -->
                        <tr v-if="shouldShowTodayMarker(transaction, index)" class="bg-gray-100">
                          <td colspan="7" class="px-6 py-2 text-center text-gray-500">
                            <em>Today - {{ formatDate(new Date()) }}</em>
                          </td>
                        </tr>

                        <!-- Transaction row -->
                        <tr :class="{'bg-blue-50': transaction.is_projected}">
                          <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900">{{ transaction.date }}</div>
                          </td>
                          <td class="px-6 py-4 whitespace-nowrap">
                              <div class="flex items-center">
                                  <template v-if="transaction.plaid_transaction?.logo_url">
                                      <img :src="transaction.plaid_transaction.logo_url"
                                           alt="Merchant Logo"
                                           class="mr-2 rounded-full"
                                           style="width: 24px; height: 24px; object-fit: cover;">
                                  </template>
                                  <template v-else-if="transaction.plaid_transaction?.personal_finance_category_icon_url">
                                      <img :src="transaction.plaid_transaction.personal_finance_category_icon_url"
                                           alt="Category Icon"
                                           class="mr-2"
                                           style="width: 24px; height: 24px; object-fit: cover;">
                                  </template>
                                  <span class="text-sm font-medium text-gray-900">{{ transaction.description }}</span>
                                  <div v-if="transaction.plaid_transaction?.pending" class="text-xs text-blue-800 bg-blue-100 px-2 py-1 rounded-full inline-block ml-4">Pending</div>
                                  <template v-if="transaction.recurring_transaction_template_id">
                                      <template v-if="transaction.is_dynamic_amount">
                                          <div class="text-xs text-orange-800 bg-orange-100 px-2 py-1 rounded-full inline-block ml-2">Variable</div>
                                      </template>
                                      <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 ml-2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                      </svg>
                                  </template>
                              </div>
                          </td>
                          <td class="px-6 py-4 whitespace-nowrap">
                            <div v-if="transaction.category" class="text-sm text-gray-900">
                              <span class="px-2 py-1 text-xs font-medium bg-gray-200 rounded-full">
                                {{ transaction.category }}
                              </span>
                            </div>
                          </td>
                          <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900">{{ transaction.account?.name || 'N/A' }}</div>
                          </td>
                          <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium" :class="transaction.amount_in_cents >= 0 ? 'text-green-600' : 'text-red-600'">
                              {{ formatCurrency(transaction.amount_in_cents) }}
                            </div>
                          </td>
                          <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium" :class="transaction.running_balance >= 0 ? 'text-green-600' : 'text-red-600'">
                              {{ formatCurrency(transaction.running_balance) }}
                            </div>
                          </td>
                          <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                            <Link
                              v-if="!transaction.is_recurring"
                              :href="route('budget.transaction.edit', [budget.id, transaction.id])"
                              class="text-indigo-600 hover:text-indigo-900"
                            >
                              Edit
                            </Link>
                            <Link
                              v-else-if="transaction.recurring_transaction_template_id"
                              :href="route('recurring-transactions.edit', [budget.id, transaction.recurring_transaction_template_id])"
                              class="text-indigo-600 hover:text-indigo-900"
                            >
                              Edit
                            </Link>
                            <span v-else class="text-gray-400">Projected</span>
                          </td>
                        </tr>
                      </template>

                      <!-- Empty state -->
                      <tr v-if="!sortedTransactions.length">
                        <td colspan="7" class="px-6 py-12 text-center text-sm text-gray-500">
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

  <!-- File Upload Modal -->
  <Modal :show="showFileUploadModal" @close="showFileUploadModal = false">
    <div class="p-6">
      <h3 class="text-lg font-medium text-gray-900 mb-4">Attach File to Budget</h3>
      <FileUpload
        :upload-url="`/budgets/${budget.id}/files`"
        @uploaded="handleFileUploaded"
        @error="handleFileError"
      />
      <div v-if="budgetAttachments.length > 0" class="mt-6">
        <FileAttachmentList
          :attachments="budgetAttachments"
          @deleted="handleFileDeleted"
        />
      </div>
    </div>
  </Modal>
</template>

<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, watch, computed, ref, onMounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { PencilIcon } from "@heroicons/vue/24/outline/index.js";
import Modal from '@/Components/Modal.vue';
import FileUpload from '@/Components/FileUpload.vue';
import FileAttachmentList from '@/Components/FileAttachmentList.vue';
import { formatCurrency } from '@/utils/format.js';

// Define props
const props = defineProps({
  budget: Object,
  accounts: Array,
  totalBalance: Number,
  transactions: Object,
  projectionParams: Object,
  categories: Array,
  filters: Object
});

// Form state for filters
const form = reactive({
  search: props.filters.search || '',
  type: props.filters.type || '',
  category: props.filters.category || '',
  pending: props.filters.pending || '',
  timeframe: props.filters.timeframe || '',
  account_id: props.filters.account_id || (props.accounts.length > 0 ? props.accounts[0].id : null)
});

// Computed property to determine the active account tab
const activeAccountId = computed(() => {
  if (!form.account_id && props.accounts.length > 0) {
    form.account_id = props.accounts[0].id;
  }
  // Convert to number for consistent comparison
  return form.account_id ? parseInt(form.account_id, 10) : null;
});

// Form state for projections
const projectionForm = reactive({
  months: props.projectionParams?.months || 1,
});

// File attachment state
const budgetAttachments = ref([]);
const showFileUploadModal = ref(false);

// Load budget attachments on mount
onMounted(() => {
  loadBudgetAttachments();
});

const loadBudgetAttachments = async () => {
  try {
    const response = await fetch(`/budgets/${props.budget.id}/files`);
    if (response.ok) {
      const data = await response.json();
      budgetAttachments.value = data.attachments;
    }
  } catch (error) {
    console.error('Failed to load budget attachments:', error);
  }
};

const handleFileUploaded = (attachment) => {
  budgetAttachments.value.push(attachment);
  showFileUploadModal.value = false;
};

const handleFileDeleted = (attachmentId) => {
  budgetAttachments.value = budgetAttachments.value.filter(a => a.id !== attachmentId);
};

const handleFileError = (error) => {
  console.error('File upload error:', error);
  // You could add toast notifications here
};

// Computed property for displayed projected transactions
const displayedProjectedTransactions = computed(() => {
  if (!props.projectedTransactions || projectionForm.months === 0) return [];

  // Convert to array if it's an object with numeric keys
    // Filter projected transactions based on the same criteria as actual transactions
  let filtered = Array.isArray(props.projectedTransactions)
      ? [...props.projectedTransactions]
      : Object.values(props.projectedTransactions || {});

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
  console.log('Filtering with account_id:', form.account_id);

  const params = {
    search: form.search || undefined,
    type: form.type || undefined,
    category: form.category || undefined,
    pending: form.pending || undefined,
    timeframe: form.timeframe || undefined,
    account_id: form.account_id || undefined,
    projection_months: projectionForm.months || undefined,
    page: 1 // Reset to first page when filtering
  };

  // Remove undefined values
  Object.keys(params).forEach(key => {
    if (params[key] === undefined) {
      delete params[key];
    }
  });

  console.log('URL params:', params);

  router.visit(route('budgets.show', props.budget.id), {
    data: params,
    preserveState: true,
    preserveScroll: true,
    replace: true
  });
}

// Update projections
function updateProjections() {
  const params = {
    search: form.search || undefined,
    category: form.category || undefined,
    timeframe: form.timeframe || undefined,
    account_id: form.account_id || undefined,
    projection_months: projectionForm.months,
    page: 1
  };

  // Remove undefined values
  Object.keys(params).forEach(key => {
    if (params[key] === undefined) {
      delete params[key];
    }
  });

  router.visit(route('budgets.show', props.budget.id), {
    data: params,
    preserveState: true,
    preserveScroll: true
  });
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
  console.log(date);
  return date.toString();
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

  if (diffHours < 12) return 'bg-green-500';
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

// Helper function to determine if a transaction should be marked as today
const hasShownTodayMarker = ref(false);

// Watch for changes in transactions data to reset the marker
watch(() => props.transactions, () => {
  hasShownTodayMarker.value = false;
}, { deep: true });

const shouldShowTodayMarker = (transaction, index) => {
  // Only check the first transaction or if we haven't shown the marker yet
  if (hasShownTodayMarker.value) return false;
  if (!transaction.date) return false;

  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const transactionDate = new Date(transaction.date);
  transactionDate.setHours(0, 0, 0, 0);

  // If this is the first transaction that's on or after today, show the marker
  if (transactionDate >= today && index > 0) {
    hasShownTodayMarker.value = true;
    return true;
  }

  // Also check if this is the last transaction before today
  if (index > 0) {
    const prevTransaction = Object.values(props.transactions.data)[index - 1];
    if (prevTransaction) {
      const prevDate = new Date(prevTransaction.date);
      prevDate.setHours(0, 0, 0, 0);

      if (prevDate < today && transactionDate >= today) {
        hasShownTodayMarker.value = true;
        return true;
      }
    }
  }

  return false;
};

// Computed property for sorted transactions
const sortedTransactions = computed(() => {
  return props.transactions.data;
  // Reset the today marker flag whenever we recalculate the sorted transactions
  hasShownTodayMarker.value = false;

  // Convert transactions data to array if it's an object with numeric keys
  const actualTransactions = Array.isArray(props.transactions.data)
    ? [...props.transactions.data]
    : Object.values(props.transactions.data || {});

  // Convert projected transactions to array if needed
  const projectedTransactions = Array.isArray(props.projectedTransactions)
    ? props.projectedTransactions
    : Object.values(props.projectedTransactions || {});

  // Add is_projected flag to projected transactions if not already present
  const projectedWithFlag = projectedTransactions.map(tx => ({
    ...tx,
    is_projected: true
  }));

  const transactions = [...actualTransactions, ...projectedWithFlag];
  return transactions.sort((a, b) => new Date(a.date) - new Date(b.date));
});

// Function to select an account
const selectAccount = (accountId) => {
  console.log('Selecting account:', accountId);
  form.account_id = accountId;

  // Use Inertia post method instead of get for better parameter handling
  router.post(route('budgets.filter', props.budget.id), {
    search: form.search || '',
    type: form.type || '',
    category: form.category || '',
    pending: form.pending || '',
    timeframe: form.timeframe || '',
    account_id: accountId,
    projection_months: projectionForm.months || 1
  }, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    onSuccess: () => {
      console.log('Filter success with account_id:', accountId);
    },
    onError: (error) => {
      console.error('Filter error:', error);
    }
  });
};

// Log initial values when mounted
onMounted(() => {
  console.log('Initial filters:', props.filters);
  console.log('Initial account_id:', props.filters.account_id);

  if (props.filters.account_id) {
    console.log('Setting initial account_id:', props.filters.account_id);
    form.account_id = props.filters.account_id;
  }
});
</script>
