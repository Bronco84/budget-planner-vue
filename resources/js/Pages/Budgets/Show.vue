<template>
  <Head :title="budget.name" />

  <AuthenticatedLayout>
    <div class="py-4">
      <div class="max-w-8xl mx-auto sm:px-2 lg:px-4">
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
          <!-- Left sidebar with Budget Overview and Accounts -->
          <div class="lg:col-span-1">
            <!-- Budget Overview Card -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
              <div class="p-4">
                  <!-- Always visible header with Total Balance -->
                  <div class="cursor-pointer" @click="budgetCardExpanded = !budgetCardExpanded">
                    <div class="flex items-center justify-between mb-2">
                      <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ budget.name }}</h2>
                      <div class="flex items-center space-x-2">
                        <!-- Expand/Collapse Icon -->
                        <svg
                          class="w-5 h-5 text-gray-500 transition-transform duration-200"
                          :class="{ 'rotate-180': budgetCardExpanded }"
                          fill="none"
                          stroke="currentColor"
                          viewBox="0 0 24 24"
                        >
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                      </div>
                    </div>

                    <!-- Always visible Total Balance -->
                    <div class="bg-gray-50 p-3 rounded-lg">
                      <div class="text-sm font-medium text-gray-500">Total Balance</div>
                      <div class="text-xl font-semibold mt-1">{{ formatCurrency(totalBalance) }}</div>

                      <!-- Projected Monthly Cash Flow Indicator -->
                      <div v-if="monthlyProjectedCashFlow !== null" class="mt-2 pt-2 border-t border-gray-200">
                        <div class="flex items-center justify-between text-xs">
                          <span class="text-gray-500">Projected Cash Flow</span>
                          <div class="flex items-center space-x-1">
                            <svg v-if="monthlyProjectedCashFlow > 0" class="w-3 h-3 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                            </svg>
                            <svg v-else-if="monthlyProjectedCashFlow < 0" class="w-3 h-3 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6" />
                            </svg>
                            <span class="font-medium" :class="monthlyProjectedCashFlow >= 0 ? 'text-green-600' : 'text-red-600'">
                              {{ formatCurrency(monthlyProjectedCashFlow) }}
                            </span>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Collapsible content -->
                  <transition
                    enter-active-class="transition duration-300 ease-out"
                    enter-from-class="transform opacity-0 -translate-y-2"
                    enter-to-class="transform opacity-100 translate-y-0"
                    leave-active-class="transition duration-200 ease-in"
                    leave-from-class="transform opacity-100 translate-y-0"
                    leave-to-class="transform opacity-0 -translate-y-2"
                  >
                    <div v-show="budgetCardExpanded" class="space-y-3 mt-3">
                      <div class="bg-gray-50 p-3 rounded-lg" v-if="budget.description">
                        <div class="text-sm font-medium text-gray-500">Description</div>
                        <div class="text-sm mt-1">{{ budget.description || 'No description provided' }}</div>
                      </div>

                      <!-- Projections Section -->
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
                      <div class="space-y-2">
                          <Link
                              :href="route('budgets.edit', budget.id)"
                              class="w-full inline-flex items-center justify-center px-3 py-2 bg-gray-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-500"
                          >
                              <PencilIcon class="w-3 h-3 mr-2" />
                              Edit Budget
                          </Link>
                          <button
                            v-if="hasPlaidAccounts"
                            @click="importFromBank"
                            class="w-full inline-flex items-center justify-center px-3 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-500"
                            :disabled="syncingTransactions"
                          >
                            <svg v-if="syncingTransactions" class="animate-spin -ml-1 mr-2 h-3 w-3 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
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
                            class="w-full inline-flex items-center justify-center px-3 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-500"
                          >
                            Connect to Bank
                          </Link>
                          <Link
                            :href="route('recurring-transactions.analysis', budget.id)"
                            class="w-full inline-flex items-center justify-center px-3 py-2 bg-purple-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-purple-500"
                          >
                            Analyze Recurring
                          </Link>
                          <Link
                            :href="route('payoff-plans.index', budget.id)"
                            class="w-full inline-flex items-center justify-center px-3 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-500"
                          >
                            Debt Payoff Plans
                          </Link>
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
                    </div>
                  </transition>
              </div>
            </div>

            <!-- Accounts Card -->
            <div class="bg-white overflow-visible shadow-sm sm:rounded-lg divide-red-50 divide-x">
              <div class="p-4">
                <div class="flex justify-between items-center mb-3">
                  <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Accounts</h3>
                  <div class="flex space-x-2">
                    <Link
                      :href="route('plaid.discover', budget.id)"
                      class="inline-flex items-center px-3 py-1 bg-green-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-500"
                      title="Import accounts from your bank"
                    >
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
                <draggable
                  v-if="accounts.length > 0"
                  v-model="draggableAccountGroups"
                  item-key="type"
                  :animation="200"
                  :disabled="false"
                  ghost-class="sortable-ghost"
                  chosen-class="sortable-chosen"
                  drag-class="sortable-drag"
                  handle=".drag-handle"
                  @start="onDragStart"
                  @end="onDragEnd"
                  @change="onDragChange"
                  class="space-y-3"
                  :class="{ 'opacity-75': isDragging }"
                >
                  <template #item="{ element: typeGroup }">
                    <div
                      :key="typeGroup.type"
                      class="transition-all duration-200"
                      :class="{ 'transform scale-[1.02] shadow-lg': isDragging }"
                    >
                      <!-- Type Header - Collapsible group header -->
                      <div class="flex justify-between items-center py-2 px-3 bg-gray-50 rounded-t-lg cursor-move drag-handle border border-gray-200 border-b-0">
                        <div class="flex items-center gap-2">
                          <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16" />
                          </svg>
                          <component 
                            :is="getAccountTypeIcon(typeGroup.type)" 
                            class="w-4 h-4"
                            :class="getGroupIconClass(typeGroup)"
                          />
                          <span class="text-xs font-semibold text-gray-600 uppercase tracking-wider">{{ typeGroup.displayName }}</span>
                          <span class="text-xs text-gray-400">({{ typeGroup.accounts.length }})</span>
                        </div>
                        <div class="text-sm font-bold tabular-nums"
                             :class="getGroupTotalColorClass(typeGroup)">
                          {{ formatCurrency(typeGroup.total) }}
                        </div>
                      </div>

                      <!-- Accounts Table -->
                      <div class="border border-gray-200 rounded-b-lg divide-y divide-gray-100">
                        <div
                          v-for="(account, index) in typeGroup.accounts"
                          :key="account.id"
                          class="cursor-pointer transition-all duration-150"
                          :class="[
                            activeAccountId === account.id 
                              ? 'bg-indigo-50 ring-1 ring-inset ring-indigo-200' 
                              : 'bg-white hover:bg-gray-50',
                          ]"
                          @click="selectAccount(account.id)"
                        >
                          <!-- Account Row -->
                          <div class="px-3 py-2.5">
                            <div class="flex justify-between items-center gap-3">
                              <!-- Account Info -->
                              <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                  <span class="font-medium text-sm text-gray-900 truncate">{{ account.name }}</span>
                                  <span 
                                    v-if="activeAccountId === account.id"
                                    class="flex-shrink-0 w-1.5 h-1.5 rounded-full bg-indigo-500"
                                  ></span>
                                </div>
                                <div v-if="account.plaid_account" class="flex items-center gap-1.5 mt-0.5">
                                  <span 
                                    class="w-1.5 h-1.5 rounded-full flex-shrink-0"
                                    :class="getLastSyncClass(account.plaid_account)"
                                  ></span>
                                  <span class="text-xs text-gray-500 truncate">
                                    <PlaidSyncTimestamp
                                      :timestamp="account.plaid_account?.plaid_connection?.last_sync_at"
                                      format="relative"
                                      never-text="never"
                                    />
                                  </span>
                                </div>
                              </div>
                              
                              <!-- Balance -->
                              <div class="flex-shrink-0 text-right">
                                <div class="text-sm font-semibold tabular-nums" :class="getBalanceColorClass(account)">
                                  {{ formatCurrency(account.current_balance_cents) }}
                                </div>
                                <span 
                                  class="text-[10px] font-medium uppercase tracking-wide"
                                  :class="account.status_classes"
                                >
                                  {{ account.status_label }}
                                </span>
                              </div>
                              
                              <!-- Actions Dropdown -->
                              <div class="flex-shrink-0 relative" @click.stop>
                                <button
                                  @click="toggleAccountDropdown(account.id)"
                                  class="p-1.5 rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 transition-colors"
                                  title="Actions"
                                >
                                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z" />
                                  </svg>
                                </button>
                                <div
                                  v-if="openDropdown === account.id"
                                  class="absolute right-0 z-50 mt-1 w-44 bg-white rounded-lg shadow-lg ring-1 ring-black ring-opacity-5 py-1"
                                  @click.stop
                                >
                                  <Link
                                    v-if="account.plaid_account"
                                    :href="route('plaid.link', [budget.id, account.id])"
                                    class="flex items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 transition-colors"
                                  >
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                    Bank Sync
                                  </Link>
                                  <Link
                                    v-if="!account.plaid_account"
                                    :href="route('plaid.link', [budget.id, account.id])"
                                    class="flex items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 transition-colors"
                                  >
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                                    </svg>
                                    Connect to Bank
                                  </Link>
                                  <Link
                                    :href="route('budget.account.balance-projection', [budget.id, account.id])"
                                    class="flex items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 transition-colors"
                                  >
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                    </svg>
                                    Balance Chart
                                  </Link>
                                  <Link
                                    :href="route('budgets.accounts.edit', [budget.id, account.id])"
                                    class="flex items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 transition-colors"
                                  >
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                    Edit
                                  </Link>
                                </div>
                              </div>
                            </div>
                            
                            <!-- Credit Card Details (if applicable) - shown below the row -->
                            <CreditCardDetails
                              v-if="account.plaid_account && activeAccountId === account.id"
                              :account="account"
                              :budget-id="budget.id"
                              :eligible-source-accounts="eligibleSourceAccounts"
                              class="mt-3 pt-3 border-t border-gray-100"
                            />
                          </div>
                        </div>
                      </div>
                    </div>
                  </template>
                </draggable>

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
                <!-- Empty State for No Accounts -->
                <div v-if="accounts.length === 0" class="text-center py-12">
                  <svg class="mx-auto h-16 w-16 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                  </svg>
                  <h3 class="text-lg font-medium text-gray-900 mb-2">No Accounts Yet</h3>
                  <p class="text-gray-600 mb-6 max-w-md mx-auto">
                    Get started by adding your first account. You can connect to your bank automatically or add an account manually.
                  </p>
                  <div class="flex flex-col sm:flex-row gap-3 justify-center">
                    <Link
                      :href="route('plaid.discover', budget.id)"
                      class="inline-flex items-center justify-center px-6 py-3 bg-blue-600 border border-transparent rounded-md font-semibold text-sm text-white hover:bg-blue-500 transition-colors"
                    >
                      <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                      </svg>
                      Connect to Bank
                    </Link>
                    <Link
                      :href="route('budgets.accounts.create', budget.id)"
                      class="inline-flex items-center justify-center px-6 py-3 bg-gray-600 border border-transparent rounded-md font-semibold text-sm text-white hover:bg-gray-500 transition-colors"
                    >
                      <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                      </svg>
                      Add Manually
                    </Link>
                  </div>
                </div>

                <!-- Transactions Table -->
                <div v-else>
                <div class="border rounded-lg overflow-x-auto">
                  <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                      <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-36">Date</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-64">Description</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-48">Category</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-36">Amount</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-36">Balance</th>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider w-24">
                          Actions
                        </th>
                      </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                      <!-- Variable to track if we've shown the today marker -->
                      <template v-for="(transaction, index) in sortedTransactions" :key="transaction.id || ('proj-' + index)">
                        <!-- Today marker -->
                        <tr v-if="shouldShowTodayMarker(transaction, index)">
                          <td colspan="7" class="px-6 py-3">
                            <div class="flex items-center justify-center">
                              <div class="flex items-center space-x-2 px-3 py-1.5 bg-indigo-100 border border-indigo-300 rounded-full shadow-sm">
                                <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                                <span class="font-semibold text-indigo-700 text-xs uppercase tracking-wide">Today</span>
                              </div>
                            </div>
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
                            <div class="text-sm font-medium" :class="transaction.amount_in_cents >= 0 ? 'text-green-600' : 'text-red-600'">
                              {{ formatCurrency(transaction.amount_in_cents) }}
                            </div>
                          </td>
                          <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium" :class="transaction.running_balance >= 0 ? 'text-green-600' : 'text-red-600'">
                              {{ formatCurrency(transaction.running_balance) }}
                            </div>
                          </td>
                          <td class="px-6 py-4 whitespace-nowrap text-right text-sm relative">
                            <!-- Projected transaction with link to edit template -->
                            <Link
                              v-if="transaction.is_projected && transaction.recurring_transaction_template_id"
                              :href="route('recurring-transactions.edit', [budget.id, transaction.recurring_transaction_template_id])"
                              class="text-indigo-600 hover:text-indigo-900 text-xs font-medium"
                            >
                              Edit Template
                            </Link>
                            <!-- Autopay projected transaction (no template) - link to account settings -->
                            <template v-else-if="transaction.is_projected && transaction.projection_source === 'autopay'">
                              <Link
                                v-if="transaction.source_account_id"
                                :href="route('budgets.accounts.edit', [budget.id, transaction.source_account_id])"
                                class="text-blue-600 hover:text-blue-900 text-xs font-medium"
                              >
                                Autopay Settings
                              </Link>
                              <span v-else class="text-gray-400 text-xs">
                                Autopay
                              </span>
                            </template>
                            <!-- Other projected transactions without template -->
                            <template v-else-if="transaction.is_projected">
                              <span class="text-gray-400 text-xs italic">
                                Projected
                              </span>
                            </template>
                            <!-- Regular transaction with recurring template -->
                            <template v-else-if="transaction.recurring_transaction_template_id">
                              <button
                                @click="toggleTransactionDropdown(transaction.id)"
                                class="inline-flex items-center px-2 py-1 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                @click.stop
                              >
                                Actions
                                <svg class="w-3 h-3 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                </svg>
                              </button>
                              <div
                                v-if="openTransactionDropdown === transaction.id"
                                class="absolute right-0 top-full z-50 mt-1 w-auto bg-white border border-gray-300 rounded-md shadow-lg"
                                @click.stop
                              >
                                <div class="py-1">
                                  <Link
                                    :href="route('recurring-transactions.edit', [budget.id, transaction.recurring_transaction_template_id])"
                                    class="block px-2 py-2 text-xs text-indigo-600 hover:bg-gray-50"
                                  >
                                    <svg class="w-3 h-3 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                    Edit Recurring Template
                                  </Link>
                                </div>
                              </div>
                            </template>
                            <!-- Regular transaction actions -->
                            <template v-else>
                              <button
                                @click="toggleTransactionDropdown(transaction.id)"
                                class="inline-flex items-center px-2 py-1 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                @click.stop
                              >
                                Actions
                                <svg class="w-3 h-3 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                </svg>
                              </button>
                              <div
                                v-if="openTransactionDropdown === transaction.id"
                                class="absolute right-0 top-full z-50 mt-1 w-auto bg-white border border-gray-300 rounded-md shadow-lg"
                                @click.stop
                              >
                                <div class="py-1">
                                  <Link
                                    :href="route('budget.transaction.edit', [budget.id, transaction.id])"
                                    class="block px-2 py-2 text-xs text-indigo-600 hover:bg-gray-50"
                                  >
                                    <svg class="w-3 h-3 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                    Edit Transaction
                                  </Link>
                                  <Link
                                    :href="route('recurring-transactions.create', {
                                      budget: budget.id,
                                      from_transaction: transaction.id
                                    })"
                                    class="block px-2 py-2 text-xs text-green-600 hover:bg-gray-50"
                                  >
                                    <svg class="w-3 h-3 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                    Make Recurring
                                  </Link>
                                </div>
                              </div>
                            </template>
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

<style scoped>
.sortable-ghost {
  opacity: 0.5;
  background: #f0f0f0;
}

.sortable-chosen {
  background: #e0e7ff;
}

.sortable-drag {
  background: #ddd6fe;
}
</style>

<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, watch, computed, ref, onMounted, onUnmounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { 
  PencilIcon,
  CreditCardIcon,
  BanknotesIcon,
  BuildingLibraryIcon,
  HomeIcon,
  ChartBarIcon,
  WalletIcon,
  CurrencyDollarIcon
} from "@heroicons/vue/24/outline/index.js";
import Modal from '@/Components/Modal.vue';
import FileUpload from '@/Components/FileUpload.vue';
import FileAttachmentList from '@/Components/FileAttachmentList.vue';
import PlaidSyncTimestamp from '@/Components/PlaidSyncTimestamp.vue';
import CreditCardDetails from '@/Components/CreditCardDetails.vue';
import { formatCurrency } from '@/utils/format.js';
import draggable from 'vuedraggable'

// Define props
const props = defineProps({
  budget: Object,
  accounts: Array,
  selectedAccountId: Number,
  totalBalance: Number,
  transactions: Object,
  projectionParams: Object,
  userAccountTypeOrder: Array,
  monthlyProjectedCashFlow: Number
});

// Form state for account selection
const form = reactive({
  account_id: props.selectedAccountId || (props.accounts.length > 0 ? props.accounts[0].id : null)
});

// Computed property to determine the active account tab
const activeAccountId = computed(() => {
  if (!form.account_id && orderedAccounts.value.length > 0) {
    // Use the first account from the user's preferred order instead of database order
    form.account_id = orderedAccounts.value[0].id;
  }
  // Convert to number for consistent comparison
  return form.account_id ? parseInt(form.account_id, 10) : null;
});

// Filter accounts that can be autopay sources (checking/savings)
const eligibleSourceAccounts = computed(() => {
  return props.accounts.filter(account => {
    return account.plaid_account?.account_type === 'depository' &&
           ['checking', 'savings'].includes(account.plaid_account?.account_subtype);
  });
});

// Group accounts by type with totals
const accountsByType = computed(() => {
  const groups = {};

  props.accounts.forEach(account => {
    const type = account.type || 'other';
    if (!groups[type]) {
      groups[type] = {
        accounts: [],
        total: 0,
        displayName: getAccountTypeDisplayName(type)
      };
    }
    groups[type].accounts.push(account);
    groups[type].total += account.current_balance_cents || 0;
  });

  // Sort groups by user's preferred order
  const userOrder = props.userAccountTypeOrder || [
    'checking', 'savings', 'money market', 'cd', 'investment',
    'credit card', 'credit', 'loan', 'line of credit', 'mortgage', 'other'
  ];

  // Create a mapping for quick lookup of order indices
  const orderMap = {};
  userOrder.forEach((type, index) => {
    orderMap[type] = index;
  });

  return Object.keys(groups)
    .sort((a, b) => (orderMap[a] ?? 999) - (orderMap[b] ?? 999))
    .map(type => ({
      type,
      ...groups[type]
    }));
});

// Helper function to get display name for account type
const getAccountTypeDisplayName = (type) => {
  const typeMap = {
    'checking': 'Checking Accounts',
    'savings': 'Savings Accounts',
    'money market': 'Money Market Accounts',
    'cd': 'Certificates of Deposit',
    'investment': 'Investment Accounts',
    'credit card': 'Credit Cards',
    'credit': 'Credit Accounts',
    'loan': 'Loans',
    'line of credit': 'Lines of Credit',
    'mortgage': 'Mortgages',
    'other': 'Other Accounts'
  };
  return typeMap[type] || type.charAt(0).toUpperCase() + type.slice(1) + ' Accounts';
};

// Form state for projections
const projectionForm = reactive({
  months: props.projectionParams?.months || 1,
});

// File attachment state
const budgetAttachments = ref([]);
const showFileUploadModal = ref(false);

// Dropdown state for account actions
const openDropdown = ref(null);

// Dropdown state for transaction actions
const openTransactionDropdown = ref(null);

// Credit card details expansion state
const expandedCreditCardAccount = ref(null);

// Budget card accordion state (collapsed by default)
const budgetCardExpanded = ref(false);

// Selected account tracking is handled by activeAccountId computed property

// Simple draggable array - this is what vue-draggable will manipulate directly
const draggableAccountGroups = ref([]);
const isDragging = ref(false);

// Methods for drag and drop
const onDragStart = () => {
  isDragging.value = true;
};

const onDragChange = () => {
  // Handle drag change events if needed
};

const onDragEnd = async (evt) => {
  isDragging.value = false;

  if (evt.oldIndex !== evt.newIndex) {
    // Extract the new order from the dragged array
    const newDisplayOrder = draggableAccountGroups.value.map(group => group.type);

    // Merge with the full user preference list to preserve ordering of types not currently shown
    const existingTypes = [...new Set(props.accounts.map(acc => acc.type || 'other'))];
    const currentFullOrder = props.userAccountTypeOrder || [];

    // Create the new full order by:
    // 1. Taking account types from newDisplayOrder that exist
    // 2. Adding remaining types from currentFullOrder in their original positions
    const newFullOrder = [
      ...newDisplayOrder,
      ...currentFullOrder.filter(type => !existingTypes.includes(type))
    ];

    try {
      // Save the new order to user preferences
      await saveAccountTypeOrder(newFullOrder);

      // Force a re-render by updating the page props
      router.reload({
        only: ['userAccountTypeOrder'],
        preserveScroll: true
      });
    } catch (error) {
      // Rebuild the draggable array from current props to revert
      rebuildDraggableArray();
    }
  }
};

// Function to rebuild the draggable array from current props
const rebuildDraggableArray = () => {
  const groups = {};

  // First, group accounts by type
  props.accounts.forEach(account => {
    const type = account.type || 'other';
    if (!groups[type]) {
      groups[type] = {
        type,
        accounts: [],
        total: 0,
        displayName: getAccountTypeDisplayName(type)
      };
    }
    groups[type].accounts.push(account);
    groups[type].total += account.current_balance_cents || 0;
  });

  // Get account types that exist
  const existingTypes = [...new Set(props.accounts.map(acc => acc.type || 'other'))];

  // Order them according to user preferences
  const userOrder = props.userAccountTypeOrder || [];
  const orderedTypes = userOrder.filter(type => existingTypes.includes(type));

  // Build the final draggable array
  draggableAccountGroups.value = orderedTypes
    .filter(type => groups[type])
    .map(type => groups[type]);
};

// Save account type order to backend
const saveAccountTypeOrder = async (order) => {
  const response = await fetch('/api/preferences/account-type-order', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
    },
    body: JSON.stringify({ order })
  });

  if (!response.ok) {
    throw new Error(`Failed to save preferences: ${response.statusText}`);
  }

  return response.json();
};

// Get accounts in the same order as the draggable groups (for tabs)
const orderedAccounts = computed(() => {
  const orderedAccountsList = [];

  for (const group of draggableAccountGroups.value) {
    orderedAccountsList.push(...group.accounts);
  }

  return orderedAccountsList;
});

// Watch for changes in props and rebuild the draggable array
watch([() => props.userAccountTypeOrder, () => props.accounts], () => {
  rebuildDraggableArray();
}, { immediate: true });

// Toggle account dropdown
const toggleAccountDropdown = (accountId) => {
  openDropdown.value = openDropdown.value === accountId ? null : accountId;
};

const toggleCreditCardDetails = (accountId) => {
  expandedCreditCardAccount.value = expandedCreditCardAccount.value === accountId ? null : accountId;
};

// Toggle transaction dropdown
const toggleTransactionDropdown = (transactionId) => {
  openTransactionDropdown.value = openTransactionDropdown.value === transactionId ? null : transactionId;
};

// Close dropdown when clicking outside
const closeDropdown = () => {
  openDropdown.value = null;
  openTransactionDropdown.value = null;
};

// Account selection functionality is handled by the existing selectAccount function

// Load budget attachments on mount
onMounted(() => {
  loadBudgetAttachments();

  // Add click outside listener
  document.addEventListener('click', closeDropdown);
});

// Cleanup on unmount
onUnmounted(() => {
  document.removeEventListener('click', closeDropdown);
});

const loadBudgetAttachments = async () => {
  try {
    const response = await fetch(`/budgets/${props.budget.id}/files`);
    if (response.ok) {
      const data = await response.json();
      budgetAttachments.value = data.attachments;
    }
  } catch (error) {
    // Failed to load attachments
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
  // Handle file upload error
};

// Computed property for displayed projected transactions
const displayedProjectedTransactions = computed(() => {
  if (!props.projectedTransactions || projectionForm.months === 0) return [];

  // Convert to array if it's an object with numeric keys
  let projected = Array.isArray(props.projectedTransactions)
      ? [...props.projectedTransactions]
      : Object.values(props.projectedTransactions || {});

  // Sort by date (newest first)
  return projected.sort((a, b) => new Date(b.date) - new Date(a.date));
});

// Update projections
function updateProjections() {
  router.visit(route('budgets.show', props.budget.id), {
    data: {
      account_id: form.account_id,
      projection_months: projectionForm.months
    },
    preserveState: true,
    preserveScroll: true
  });
}

// Helper functions for formatting dates
const formatDate = (dateString) => {
  if (!dateString) return 'N/A';
  const date = new Date(dateString);
  return date.toString();
};


// Helper function to get balance color class based on account type
const getBalanceColorClass = (account) => {
  const liabilityAccountTypes = ['mortgage', 'line of credit', 'credit', 'credit card', 'loan'];

  if (liabilityAccountTypes.includes(account.type)) {
    return 'text-gray-600';
  }

  return account.current_balance_cents >= 0 ? 'text-green-600' : 'text-red-600';
};

// Helper function to get border color class based on account type
const getBorderColorClass = (account) => {
  const liabilityAccountTypes = ['mortgage', 'line of credit', 'credit', 'credit card', 'loan'];

  if (liabilityAccountTypes.includes(account.type)) {
    return 'border-gray-400';
  }

  return account.current_balance_cents >= 0 ? 'border-green-500' : 'border-red-500';
};

// Helper function to get group total color class based on account type
const getGroupTotalColorClass = (typeGroup) => {
  const liabilityAccountTypes = ['mortgage', 'line of credit', 'credit', 'credit card', 'loan'];

  if (liabilityAccountTypes.includes(typeGroup.type)) {
    return 'text-gray-600';
  }

  return typeGroup.total >= 0 ? 'text-green-600' : 'text-red-600';
};

// Account type icon mapping
const accountTypeIcons = {
  'checking': BanknotesIcon,
  'savings': WalletIcon,
  'credit card': CreditCardIcon,
  'credit': CreditCardIcon,
  'investment': ChartBarIcon,
  'brokerage': ChartBarIcon,
  'mortgage': HomeIcon,
  'loan': BuildingLibraryIcon,
  'line of credit': CurrencyDollarIcon,
};

// Helper function to get the icon component for an account type
const getAccountTypeIcon = (accountType) => {
  const normalizedType = accountType?.toLowerCase() || '';
  return accountTypeIcons[normalizedType] || BanknotesIcon;
};

// Helper function to get icon color based on account type
const getAccountIconClass = (account) => {
  const liabilityAccountTypes = ['mortgage', 'line of credit', 'credit', 'credit card', 'loan'];
  if (liabilityAccountTypes.includes(account.type?.toLowerCase())) {
    return 'text-amber-500';
  }
  return 'text-emerald-500';
};

// Helper function to get icon color for a group header
const getGroupIconClass = (typeGroup) => {
  const liabilityAccountTypes = ['mortgage', 'line of credit', 'credit', 'credit card', 'loan'];
  if (liabilityAccountTypes.includes(typeGroup.type?.toLowerCase())) {
    return 'text-amber-500';
  }
  return 'text-emerald-500';
};


// State for Plaid sync
const syncingTransactions = ref(false);

// Check if any accounts have Plaid connections
const hasPlaidAccounts = computed(() => {
  return props.accounts.some(account => account.plaid_account !== null);
});

// Import transactions from all Plaid-connected accounts
const importFromBank = () => {
  // Get all accounts with Plaid connections
  const plaidAccounts = props.accounts.filter(account => account.plaid_account !== null);

  if (plaidAccounts.length === 0) {
    alert('No Plaid-connected accounts found. Please connect an account to Plaid first.');
    return;
  }

  // Check if we've already synced today to avoid unnecessary API costs
  const syncTimes = plaidAccounts
    .map(account => account.plaid_account?.plaid_connection?.last_sync_at)
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

  router.post(
    syncAllUrl,
    {},
    {
      preserveScroll: true,
      onSuccess: (page) => {
        syncingTransactions.value = false;

        // Show success message to user
        if (page.props.flash && page.props.flash.message) {
          alert(page.props.flash.message);
        }

        // Reload only the necessary components
        router.reload({
          only: ['transactions', 'accounts'],
          preserveScroll: true
        });
      },
      onError: (errors) => {
        syncingTransactions.value = false;

        // Show detailed error information
        let errorMessage = 'Failed to sync transactions. Please try again.';

        if (errors.message) {
          errorMessage = errors.message;
        } else if (errors.response && errors.response.status) {
          errorMessage = `Server returned error code ${errors.response.status}`;
        }

        alert(errorMessage);
      }
    }
  );
};

// Computed property to get connected accounts
const plaidConnectedAccounts = computed(() => {
  return props.accounts.filter(account => account.plaid_account !== null);
});


// Function to get a colored indicator based on how recent the sync is
const getLastSyncClass = (plaidAccount) => {
  if (!plaidAccount?.plaid_connection?.last_sync_at) return 'bg-gray-400';

  const lastSync = new Date(plaidAccount.plaid_connection.last_sync_at);
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
      .map(account => account.plaid_account?.plaid_connection?.last_sync_at)
      .filter(time => time !== null && time !== undefined);

    if (syncTimes.length > 0) {
      const mostRecentSync = new Date(Math.max(...syncTimes.map(time => new Date(time).getTime())));
      const now = new Date();
      const diffHours = (now - mostRecentSync) / (1000 * 60 * 60);

      // Only auto-sync if we haven't synced in the last 12 hours
      if (diffHours > 12) {
        importFromBank();
      }
    } else {
      // No sync history, do initial sync
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
    const prevTransaction = sortedTransactions.value[index - 1];
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
  return transactions.sort((a, b) => new Date(b.date) - new Date(a.date));
});

// Function to select an account
const selectAccount = (accountId) => {
  form.account_id = accountId;

  // Navigate to the budget page with the selected account
  router.visit(route('budgets.show', props.budget.id), {
    data: {
      account_id: accountId,
      projection_months: projectionForm.months || 1
    },
    preserveState: true,
    preserveScroll: true,
    replace: true
  });
};

// Calculate monthly cash flow from the currently selected account
const monthlyCashFlow = computed(() => {
  if (!props.transactions || !props.transactions.data || props.transactions.data.length === 0) {
    return null;
  }

  // Only calculate for the currently selected account
  if (!activeAccountId.value) {
    return null;
  }

  // Calculate net cash flow from last 30 days of transactions
  // Note: props.transactions.data is already filtered by account_id on the backend
  const now = new Date();
  const thirtyDaysAgo = new Date(now.getTime() - (30 * 24 * 60 * 60 * 1000));

  let totalFlow = 0;
  let count = 0;

  props.transactions.data.forEach(transaction => {
    if (transaction.is_projected) return; // Skip projected transactions

    const txDate = new Date(transaction.date);
    if (txDate >= thirtyDaysAgo && txDate <= now) {
      totalFlow += transaction.amount_in_cents || 0;
      count++;
    }
  });

  // If we have data, return the total (it's already roughly monthly since it's 30 days)
  if (count > 0) {
    return totalFlow;
  }

  return null;
});
</script>
