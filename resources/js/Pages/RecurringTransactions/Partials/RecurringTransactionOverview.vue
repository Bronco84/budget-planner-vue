<template>
  <div class="space-y-6">
    <!-- Transaction Details -->
    <div>
      <h3 class="text-lg font-medium text-gray-900 mb-4">Transaction Details</h3>

      <form @submit.prevent="submit">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <!-- Account Selection -->
          <div>
            <InputLabel for="account_id" value="Account" />
            <SelectInput
              id="account_id"
              v-model="form.account_id"
              class="mt-1 block w-full"
              required
            >
              <option value="" disabled>Select an account</option>
              <option v-for="account in accounts" :key="account.id" :value="account.id">
                {{ account.name }}
              </option>
            </SelectInput>
            <InputError class="mt-2" :message="form.errors.account_id" />
          </div>

          <!-- Description -->
          <div>
            <InputLabel for="description" value="Description" />
            <TextInput
              id="description"
              type="text"
              class="mt-1 block w-full"
              v-model="form.description"
              required
            />
            <InputError class="mt-2" :message="form.errors.description" />
          </div>

          <!-- Category -->
          <div>
            <InputLabel for="category" value="Category" />
            <TextInput
              id="category"
              type="text"
              class="mt-1 block w-full"
              v-model="form.category"
            />
            <InputError class="mt-2" :message="form.errors.category" />
          </div>

          <!-- Amount Type -->
          <div>
            <InputLabel value="Amount Type" />
            <div class="mt-2 space-y-2">
              <div class="flex items-center">
                <input
                  id="static-amount"
                  type="radio"
                  v-model="form.is_dynamic_amount"
                  :value="false"
                  class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300"
                />
                <label for="static-amount" class="ml-2 block text-sm text-gray-700">
                  Static Amount
                </label>
              </div>
              <div class="flex items-center">
                <input
                  id="dynamic-amount"
                  type="radio"
                  v-model="form.is_dynamic_amount"
                  :value="true"
                  class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300"
                />
                <label for="dynamic-amount" class="ml-2 block text-sm text-gray-700">
                  Dynamic Amount
                </label>
              </div>
            </div>
          </div>

          <!-- Amount (Static) -->
          <div v-if="!form.is_dynamic_amount">
            <InputLabel for="amount" value="Amount" />
            <div class="mt-1 relative rounded-md shadow-sm">
              <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <span class="text-gray-500 sm:text-sm">$</span>
              </div>
              <input
                id="amount"
                type="number"
                step="0.01"
                v-model="form.amount"
                class="focus:ring-indigo-500 focus:border-indigo-500 block w-full pl-7 pr-12 sm:text-sm border-gray-300 rounded-md"
                required
              />
            </div>
            <p class="mt-1 text-sm text-gray-500">Use positive values for income, negative for expenses (e.g., -50.00)</p>
            <InputError class="mt-2" :message="form.errors.amount" />
          </div>

          <!-- Dynamic Amount Options -->
          <div v-if="form.is_dynamic_amount" class="md:col-span-2 grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <InputLabel for="min_amount" value="Minimum Amount (Optional)" />
              <div class="mt-1 relative rounded-md shadow-sm">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <span class="text-gray-500 sm:text-sm">$</span>
                </div>
                <input
                  id="min_amount"
                  type="number"
                  step="0.01"
                  v-model="form.min_amount"
                  class="focus:ring-indigo-500 focus:border-indigo-500 block w-full pl-7 pr-12 sm:text-sm border-gray-300 rounded-md"
                />
              </div>
            </div>
            <div>
              <InputLabel for="max_amount" value="Maximum Amount (Optional)" />
              <div class="mt-1 relative rounded-md shadow-sm">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <span class="text-gray-500 sm:text-sm">$</span>
                </div>
                <input
                  id="max_amount"
                  type="number"
                  step="0.01"
                  v-model="form.max_amount"
                  class="focus:ring-indigo-500 focus:border-indigo-500 block w-full pl-7 pr-12 sm:text-sm border-gray-300 rounded-md"
                />
              </div>
            </div>
            <div>
              <InputLabel for="average_amount" value="Starting Average (Optional)" />
              <div class="mt-1 relative rounded-md shadow-sm">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <span class="text-gray-500 sm:text-sm">$</span>
                </div>
                <input
                  id="average_amount"
                  type="number"
                  step="0.01"
                  v-model="form.average_amount"
                  class="focus:ring-indigo-500 focus:border-indigo-500 block w-full pl-7 pr-12 sm:text-sm border-gray-300 rounded-md"
                />
              </div>
            </div>
          </div>

          <!-- Notes -->
          <div class="md:col-span-2">
            <InputLabel for="notes" value="Notes (Optional)" />
            <textarea
              id="notes"
              v-model="form.notes"
              rows="3"
              class="mt-1 shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md"
            ></textarea>
            <InputError class="mt-2" :message="form.errors.notes" />
          </div>
        </div>

        <!-- Autopay Override Section -->
        <div v-if="eligibleCreditCards && eligibleCreditCards.length > 0" class="mt-6 border-t border-gray-200 pt-6">
          <h4 class="text-md font-medium text-gray-900 mb-2">Autopay Override</h4>
          <p class="text-sm text-gray-600 mb-4">
            Link this recurring transaction to a credit card with autopay enabled. When the statement balance is available, the autopay projection will override this recurring transaction's projection for that month.
          </p>
          
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <InputLabel for="linked_credit_card_account_id" value="Linked Credit Card" />
              <SelectInput
                id="linked_credit_card_account_id"
                v-model="form.linked_credit_card_account_id"
                class="mt-1 block w-full"
              >
                <option :value="null">None (no autopay override)</option>
                <option v-for="card in eligibleCreditCards" :key="card.id" :value="card.id">
                  {{ card.institution_name || 'Unknown' }} (...{{ card.account_mask }}) - {{ card.name }}
                </option>
              </SelectInput>
              <InputError class="mt-2" :message="form.errors.linked_credit_card_account_id" />
            </div>

            <!-- Show linked card info if selected -->
            <div v-if="selectedCreditCard" class="bg-blue-50 border border-blue-200 rounded-md p-4">
              <div class="text-sm">
                <div class="flex justify-between mb-1">
                  <span class="text-gray-600">Statement Balance:</span>
                  <span class="font-medium text-gray-900">{{ formatCurrencyAmount(selectedCreditCard.statement_balance_cents) }}</span>
                </div>
                <div class="flex justify-between">
                  <span class="text-gray-600">Next Payment Due:</span>
                  <span class="font-medium text-gray-900">{{ selectedCreditCard.next_payment_due_date || 'N/A' }}</span>
                </div>
              </div>
              <p class="text-xs text-blue-700 mt-2">
                This recurring projection will be skipped for the month when autopay uses the actual statement balance.
              </p>
            </div>
          </div>
        </div>

        <!-- Transaction Matching Strategy Section -->
        <div class="mt-6 border-t border-gray-200 pt-6">
          <h4 class="text-md font-medium text-gray-900 mb-4">Transaction Matching Strategy</h4>
          <p class="text-sm text-gray-600 mb-4">
            Transactions are automatically matched using a priority-based system. The first matching method found is used.
          </p>
          
          <div class="space-y-3">
            <!-- Priority 1: Plaid Entity ID -->
            <div class="border rounded-lg p-4" :class="recurringTransaction.plaid_entity_id ? 'bg-green-50 border-green-200' : 'bg-gray-50 border-gray-200'">
              <div class="flex items-start space-x-3">
                <div class="flex-shrink-0 mt-0.5">
                  <svg v-if="recurringTransaction.plaid_entity_id" class="h-5 w-5 text-green-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                  </svg>
                  <div v-else class="h-5 w-5 rounded-full border-2 border-gray-300"></div>
                </div>
                <div class="flex-1 min-w-0">
                  <div class="flex items-center justify-between">
                    <span class="text-sm font-medium" :class="recurringTransaction.plaid_entity_id ? 'text-green-900' : 'text-gray-700'">
                      Priority 1: Plaid Entity ID
                    </span>
                    <span v-if="recurringTransaction.plaid_entity_id" class="text-xs bg-green-100 text-green-800 px-2 py-0.5 rounded-full font-medium">
                      ACTIVE
                    </span>
                  </div>
                  <p class="mt-1 text-sm" :class="recurringTransaction.plaid_entity_id ? 'text-green-700' : 'text-gray-500'">
                    <template v-if="recurringTransaction.plaid_entity_id">
                      <span class="font-medium">{{ recurringTransaction.plaid_entity_name || 'Unknown Entity' }}</span>
                      <span class="block text-xs mt-1 font-mono">ID: {{ recurringTransaction.plaid_entity_id }}</span>
                      <span class="block mt-2 text-xs">
                        Most reliable method. All transactions from this merchant are automatically matched regardless of description variations.
                      </span>
                    </template>
                    <template v-else>
                      Not configured. Use transaction analysis to capture entity ID for best matching accuracy.
                    </template>
                  </p>
                </div>
              </div>
            </div>

            <!-- Priority 2: Rules -->
            <div class="border rounded-lg p-4" :class="activeRulesCount > 0 ? 'bg-blue-50 border-blue-200' : 'bg-gray-50 border-gray-200'">
              <div class="flex items-start space-x-3">
                <div class="flex-shrink-0 mt-0.5">
                  <svg v-if="activeRulesCount > 0" class="h-5 w-5 text-blue-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                  </svg>
                  <div v-else class="h-5 w-5 rounded-full border-2 border-gray-300"></div>
                </div>
                <div class="flex-1 min-w-0">
                  <div class="flex items-center justify-between">
                    <span class="text-sm font-medium" :class="activeRulesCount > 0 ? 'text-blue-900' : 'text-gray-700'">
                      Priority 2: Rules ({{ activeRulesCount }} active)
                    </span>
                    <span v-if="activeRulesCount > 0" class="text-xs bg-blue-100 text-blue-800 px-2 py-0.5 rounded-full font-medium">
                      ACTIVE
                    </span>
                  </div>
                  <p class="mt-1 text-sm" :class="activeRulesCount > 0 ? 'text-blue-700' : 'text-gray-500'">
                    <template v-if="activeRulesCount > 0">
                      Transactions must match ALL {{ activeRulesCount }} active {{ activeRulesCount === 1 ? 'rule' : 'rules' }} to be linked.
                      <span class="block mt-1 text-xs">
                        Used when no entity ID is available. Provides precise control over matching criteria.
                      </span>
                    </template>
                    <template v-else>
                      No active rules. Add rules in the Rules tab for more precise matching control.
                    </template>
                  </p>
                </div>
              </div>
            </div>

            <!-- Priority 3: Description -->
            <div class="border rounded-lg p-4" :class="!recurringTransaction.plaid_entity_id && activeRulesCount === 0 ? 'bg-yellow-50 border-yellow-200' : 'bg-gray-50 border-gray-200'">
              <div class="flex items-start space-x-3">
                <div class="flex-shrink-0 mt-0.5">
                  <svg v-if="!recurringTransaction.plaid_entity_id && activeRulesCount === 0" class="h-5 w-5 text-yellow-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                  </svg>
                  <div v-else class="h-5 w-5 rounded-full border-2 border-gray-300"></div>
                </div>
                <div class="flex-1 min-w-0">
                  <div class="flex items-center justify-between">
                    <span class="text-sm font-medium" :class="!recurringTransaction.plaid_entity_id && activeRulesCount === 0 ? 'text-yellow-900' : 'text-gray-700'">
                      Priority 3: Description Matching
                    </span>
                    <span v-if="!recurringTransaction.plaid_entity_id && activeRulesCount === 0" class="text-xs bg-yellow-100 text-yellow-800 px-2 py-0.5 rounded-full font-medium">
                      FALLBACK
                    </span>
                  </div>
                  <p class="mt-1 text-sm" :class="!recurringTransaction.plaid_entity_id && activeRulesCount === 0 ? 'text-yellow-700' : 'text-gray-500'">
                    <template v-if="!recurringTransaction.plaid_entity_id && activeRulesCount === 0">
                      Currently matching: "{{ recurringTransaction.description }}"
                      <span class="block mt-1 text-xs">
                        Matches transactions containing this text (70%+ similarity) in the original Plaid description. Category is not enforced.
                      </span>
                      <span class="block mt-2 text-xs font-medium">
                        💡 Tip: Use "Test Matching" to see what matches, or add rules/capture entity ID for better accuracy.
                      </span>
                    </template>
                    <template v-else>
                      Fallback method. Only used when entity ID and rules don't match.
                    </template>
                  </p>
                </div>
              </div>
            </div>
          </div>
          
          <!-- Test Matching Button -->
          <div class="mt-4 pt-4 border-t">
            <button
              type="button"
              @click="testMatching"
              class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 active:bg-indigo-800 focus:outline-none focus:border-indigo-900 focus:ring focus:ring-indigo-300 disabled:opacity-25 transition"
              :disabled="loadingTestResults"
            >
              <svg v-if="!loadingTestResults" class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
              </svg>
              <svg v-else class="animate-spin h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              {{ loadingTestResults ? 'Testing...' : 'Test Current Matching' }}
            </button>
            <p class="mt-2 text-xs text-gray-500">
              Preview which recent transactions would match with current settings
            </p>
          </div>
        </div>

        <div class="mt-6 border-t border-gray-200 pt-6">
          <h4 class="text-md font-medium text-gray-900 mb-4">Recurring Options</h4>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Frequency -->
            <div>
              <InputLabel for="frequency" value="Frequency" />
              <SelectInput
                id="frequency"
                v-model="form.frequency"
                class="mt-1 block w-full"
                required
              >
                <option value="" disabled>Select frequency</option>
                <option value="daily">Daily</option>
                <option value="weekly">Weekly</option>
                <option value="biweekly">Every Two Weeks</option>
                <option value="monthly">Monthly</option>
                <option value="bimonthly">Twice a Month</option>
                <option value="quarterly">Quarterly</option>
                <option value="yearly">Yearly</option>
              </SelectInput>
              <InputError class="mt-2" :message="form.errors.frequency" />
            </div>

            <!-- Day of Week (for weekly/biweekly) -->
            <div v-if="form.frequency === 'weekly' || form.frequency === 'biweekly'">
              <InputLabel for="day_of_week" value="Day of Week" />
              <SelectInput
                id="day_of_week"
                v-model="form.day_of_week"
                class="mt-1 block w-full"
              >
                <option value="" disabled>Select day of week</option>
                <option value="0">Sunday</option>
                <option value="1">Monday</option>
                <option value="2">Tuesday</option>
                <option value="3">Wednesday</option>
                <option value="4">Thursday</option>
                <option value="5">Friday</option>
                <option value="6">Saturday</option>
              </SelectInput>
              <p class="mt-1 text-sm text-gray-500">The day of the week when the transaction occurs</p>
              <InputError class="mt-2" :message="form.errors.day_of_week" />
            </div>

            <!-- Day of Month (for monthly/quarterly) -->
            <div v-if="form.frequency === 'monthly' || form.frequency === 'quarterly'">
              <InputLabel for="day_of_month" value="Day of Month" />
              <input
                id="day_of_month"
                type="number"
                min="1"
                max="31"
                v-model="form.day_of_month"
                class="mt-1 focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md"
              />
              <p class="mt-1 text-sm text-gray-500">The day of the month when the transaction occurs (1-31)</p>
              <InputError class="mt-2" :message="form.errors.day_of_month" />
            </div>

            <!-- Bimonthly frequency fields -->
            <div v-if="form.frequency === 'bimonthly'" class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4">
              <!-- First occurrence -->
              <div>
                <InputLabel for="first_day_of_month" value="First Occurrence" />
                <input
                  id="first_day_of_month"
                  type="number"
                  min="1"
                  max="31"
                  v-model.number="form.first_day_of_month"
                  class="mt-1 focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md"
                  required
                />
                <p class="mt-1 text-sm text-gray-500">First day of the month (e.g., 1 for 1st)</p>
                <InputError class="mt-2" :message="form.errors.first_day_of_month" />
              </div>

              <!-- Second occurrence -->
              <div>
                <InputLabel for="day_of_month" value="Second Occurrence" />
                <input
                  id="day_of_month"
                  type="number"
                  min="1"
                  max="31"
                  v-model.number="form.day_of_month"
                  class="mt-1 focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md"
                  required
                />
                <p class="mt-1 text-sm text-gray-500">Second day of the month (e.g., 15 for 15th)</p>
                <InputError class="mt-2" :message="form.errors.day_of_month" />
              </div>
            </div>

            <!-- Helper text for bimonthly -->
            <div v-if="form.frequency === 'bimonthly'" class="md:col-span-2 bg-blue-50 p-3 rounded-md">
              <p class="text-sm text-blue-700">
                <strong>Example:</strong> For transactions on the 1st and 15th of every month, enter 1 and 15.
                The transaction will occur twice per month on these specific days.
              </p>
            </div>

            <!-- Start Date -->
            <div>
              <InputLabel for="start_date" value="Start Date" />
              <TextInput
                id="start_date"
                type="date"
                class="mt-1 block w-full"
                v-model="form.start_date"
                required
              />
              <InputError class="mt-2" :message="form.errors.start_date" />
            </div>

            <!-- End Date -->
            <div>
              <InputLabel for="end_date" value="End Date (Optional)" />
              <TextInput
                id="end_date"
                type="date"
                class="mt-1 block w-full"
                v-model="form.end_date"
              />
              <InputError class="mt-2" :message="form.errors.end_date" />
            </div>
          </div>
        </div>

        <!-- Action Buttons -->
        <div class="mt-6 flex items-center justify-between">
          <button
            type="button"
            @click="confirmDelete"
            class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-700 active:bg-red-800 focus:outline-none focus:border-red-900 focus:ring focus:ring-red-300 disabled:opacity-25 transition"
          >
            Delete
          </button>
          <div class="flex space-x-3">
            <Link
              :href="route('recurring-transactions.index', budget.id)"
              class="inline-flex items-center px-4 py-2 bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-300 active:bg-gray-400 focus:outline-none focus:border-gray-900 focus:ring focus:ring-gray-300 disabled:opacity-25 transition"
            >
              Cancel
            </Link>
            <button
              type="submit"
              :disabled="form.processing"
              class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 active:bg-gray-900 focus:outline-none focus:border-gray-900 focus:ring focus:ring-gray-300 disabled:opacity-25 transition"
            >
              Update Recurring Transaction
            </button>
          </div>
        </div>
      </form>
    </div>

    <!-- Test Matching Results Modal -->
    <TransitionRoot appear :show="showTestModal" as="template">
      <Dialog as="div" @close="showTestModal = false" class="relative z-50">
        <TransitionChild
          as="template"
          enter="duration-300 ease-out"
          enter-from="opacity-0"
          enter-to="opacity-100"
          leave="duration-200 ease-in"
          leave-from="opacity-100"
          leave-to="opacity-0"
        >
          <div class="fixed inset-0 bg-black bg-opacity-25" />
        </TransitionChild>

        <div class="fixed inset-0 overflow-y-auto">
          <div class="flex min-h-full items-center justify-center p-4 text-center">
            <TransitionChild
              as="template"
              enter="duration-300 ease-out"
              enter-from="opacity-0 scale-95"
              enter-to="opacity-100 scale-100"
              leave="duration-200 ease-in"
              leave-from="opacity-100 scale-100"
              leave-to="opacity-0 scale-95"
            >
              <DialogPanel class="w-full max-w-4xl transform overflow-hidden rounded-2xl bg-white p-6 text-left align-middle shadow-xl transition-all">
                <DialogTitle as="h3" class="text-lg font-medium leading-6 text-gray-900 mb-4">
                  Test Matching Results
                </DialogTitle>
                
                <div v-if="testResults" class="space-y-4">
                  <!-- Summary -->
                  <div class="bg-gray-50 rounded-lg p-4">
                    <div class="grid grid-cols-3 gap-4 text-center">
                      <div>
                        <div class="text-2xl font-bold text-gray-900">{{ testResults.total_tested }}</div>
                        <div class="text-sm text-gray-600">Transactions Tested</div>
                      </div>
                      <div>
                        <div class="text-2xl font-bold text-green-600">{{ testResults.matches_found }}</div>
                        <div class="text-sm text-gray-600">Matches Found</div>
                      </div>
                      <div>
                        <div class="text-2xl font-bold text-blue-600">
                          {{ testResults.total_tested > 0 ? Math.round((testResults.matches_found / testResults.total_tested) * 100) : 0 }}%
                        </div>
                        <div class="text-sm text-gray-600">Match Rate</div>
                      </div>
                    </div>
                  </div>

                  <!-- Matches List -->
                  <div v-if="testResults.matches_found > 0" class="max-h-96 overflow-y-auto">
                    <h4 class="text-sm font-medium text-gray-700 mb-2">Matching Transactions</h4>
                    <div class="space-y-2">
                      <div
                        v-for="match in testResults.matches"
                        :key="match.transaction.id"
                        class="border rounded-lg p-3 hover:bg-gray-50"
                      >
                        <div class="flex items-start justify-between">
                          <div class="flex-1 min-w-0">
                            <div class="flex items-center space-x-2">
                              <span class="text-sm font-medium text-gray-900">{{ match.transaction.description }}</span>
                              <span :class="getMatchMethodColor(match.match_method)" class="px-2 py-0.5 rounded-full text-xs font-medium">
                                {{ getMatchMethodLabel(match.match_method) }}
                              </span>
                            </div>
                            <div v-if="match.transaction.plaid_description && match.transaction.plaid_description !== match.transaction.description" class="mt-1 text-xs text-gray-500">
                              <span class="font-medium">Plaid:</span> {{ match.transaction.plaid_description }}
                            </div>
                            <div class="mt-1 flex items-center space-x-4 text-xs text-gray-500">
                              <span>{{ match.transaction.date }}</span>
                              <span v-if="match.transaction.category" class="px-2 py-0.5 bg-gray-100 rounded">{{ match.transaction.category }}</span>
                              <span class="font-mono" :class="match.transaction.amount < 0 ? 'text-red-600' : 'text-green-600'">
                                {{ formatCurrencyAmount(match.transaction.amount * 100) }}
                              </span>
                            </div>
                            <div v-if="match.match_details" class="mt-1 text-xs text-gray-600">
                              {{ match.match_details }}
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- No Matches -->
                  <div v-else class="text-center py-8">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <h3 class="mt-2 text-sm font-medium text-gray-900">No Matches Found</h3>
                    <p class="mt-1 text-sm text-gray-500">
                      No recent transactions match the current settings. Try adjusting your matching criteria.
                    </p>
                  </div>
                </div>

                <div class="mt-6 flex justify-end">
                  <button
                    type="button"
                    class="inline-flex justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2"
                    @click="showTestModal = false"
                  >
                    Close
                  </button>
                </div>
              </DialogPanel>
            </TransitionChild>
          </div>
        </div>
      </Dialog>
    </TransitionRoot>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useForm, Link, router } from '@inertiajs/vue3';
import { Dialog, DialogPanel, DialogTitle, TransitionRoot, TransitionChild } from '@headlessui/vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import SelectInput from '@/Components/SelectInput.vue';
import InputError from '@/Components/InputError.vue';
import { formatCurrency } from '@/utils/format.js';
import { useToast } from '@/composables/useToast';
import axios from 'axios';

const toast = useToast();

const showTestModal = ref(false);
const loadingTestResults = ref(false);
const testResults = ref(null);

const props = defineProps({
  budget: Object,
  recurringTransaction: Object,
  accounts: Array,
  linkedTransactions: Array,
  rules: Array,
  eligibleCreditCards: {
    type: Array,
    default: () => [],
  },
});

const form = useForm({
  account_id: props.recurringTransaction.account_id,
  linked_credit_card_account_id: props.recurringTransaction.linked_credit_card_account_id,
  description: props.recurringTransaction.description,
  category: props.recurringTransaction.category,
  amount: props.recurringTransaction.amount_in_cents / 100,
  is_dynamic_amount: props.recurringTransaction.is_dynamic_amount,
  min_amount: props.recurringTransaction.min_amount ? Math.abs(props.recurringTransaction.min_amount / 100) : '',
  max_amount: props.recurringTransaction.max_amount ? Math.abs(props.recurringTransaction.max_amount / 100) : '',
  average_amount: props.recurringTransaction.average_amount ? props.recurringTransaction.average_amount / 100 : '',
  frequency: props.recurringTransaction.frequency,
  day_of_week: props.recurringTransaction.day_of_week,
  day_of_month: props.recurringTransaction.day_of_month,
  first_day_of_month: props.recurringTransaction.first_day_of_month,
  start_date: props.recurringTransaction.start_date,
  end_date: props.recurringTransaction.end_date,
  notes: props.recurringTransaction.notes,
});

// Helper to format currency for the credit card dropdown
const formatCurrencyAmount = (cents) => {
  if (!cents) return '';
  return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(cents / 100);
};

// Get the selected credit card details
const selectedCreditCard = computed(() => {
  if (!form.linked_credit_card_account_id) return null;
  return props.eligibleCreditCards?.find(c => c.id === form.linked_credit_card_account_id);
});

// Count active rules for matching strategy display
const activeRulesCount = computed(() => {
  return props.rules ? props.rules.filter(r => r.is_active).length : 0;
});

const totalAmount = computed(() => {
  return props.linkedTransactions.reduce((sum, t) => sum + t.amount_in_cents, 0);
});

const formatDate = (dateString) => {
  if (!dateString) return 'N/A';
  const date = new Date(dateString);
  return date.toLocaleDateString();
};

const submit = () => {
  form.patch(route('recurring-transactions.update', {
    budget: props.budget.id,
    recurring_transaction: props.recurringTransaction.id
  }), {
    onSuccess: () => {
      toast.success('Recurring transaction updated successfully');
    },
    onError: (errors) => {
      // Show the first error message
      const firstError = Object.values(errors)[0];
      if (firstError) {
        toast.error(Array.isArray(firstError) ? firstError[0] : firstError);
      } else {
        toast.error('Failed to update recurring transaction');
      }
    }
  });
};

const confirmDelete = async () => {
  const confirmed = await toast.confirm({
    title: 'Delete Recurring Transaction',
    message: `Are you sure you want to delete the recurring transaction "${props.recurringTransaction.description}"?`,
    confirmText: 'Delete',
    cancelText: 'Cancel',
    type: 'danger'
  });
  
  if (confirmed) {
    router.delete(route('recurring-transactions.destroy', [props.budget.id, props.recurringTransaction.id]));
  }
};

const testMatching = async () => {
  loadingTestResults.value = true;
  try {
    const response = await axios.get(route('recurring-transactions.test-matching', {
      budget: props.budget.id,
      recurring_transaction: props.recurringTransaction.id
    }));
    testResults.value = response.data;
    showTestModal.value = true;
  } catch (error) {
    console.error('Failed to test matching:', error);
    toast.error('Failed to test matching');
  } finally {
    loadingTestResults.value = false;
  }
};

const getMatchMethodLabel = (method) => {
  const labels = {
    'entity_id': 'Plaid Entity ID',
    'rules': 'Rules',
    'description_exact': 'Description (Exact)',
    'description_contains': 'Description (Contains)',
    'description_fuzzy': 'Description (Fuzzy)',
  };
  return labels[method] || method;
};

const getMatchMethodColor = (method) => {
  if (method === 'entity_id') return 'text-green-600 bg-green-50';
  if (method === 'rules') return 'text-blue-600 bg-blue-50';
  return 'text-yellow-600 bg-yellow-50';
};
</script>
