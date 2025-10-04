<template>
  <AuthenticatedLayout :title="`Create Payoff Plan - ${budget.name}`">
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        Create Debt Payoff Plan
      </h2>
    </template>

    <div class="py-12">
      <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <!-- Progress Steps -->
          <div class="border-b border-gray-200 bg-gray-50 px-6 py-4">
            <nav class="flex items-center justify-center space-x-4">
              <div
                v-for="(step, index) in steps"
                :key="index"
                class="flex items-center"
              >
                <div class="flex items-center space-x-2">
                  <div
                    :class="[
                      'flex items-center justify-center w-8 h-8 rounded-full text-sm font-medium',
                      currentStep === index
                        ? 'bg-indigo-600 text-white'
                        : currentStep > index
                        ? 'bg-green-600 text-white'
                        : 'bg-gray-300 text-gray-600'
                    ]"
                  >
                    <svg v-if="currentStep > index" class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                      <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                    </svg>
                    <span v-else>{{ index + 1 }}</span>
                  </div>
                  <span
                    :class="[
                      'text-sm font-medium',
                      currentStep === index ? 'text-indigo-600' : 'text-gray-500'
                    ]"
                  >
                    {{ step }}
                  </span>
                </div>
                <svg
                  v-if="index < steps.length - 1"
                  class="w-5 h-5 text-gray-300 mx-2"
                  fill="currentColor"
                  viewBox="0 0 20 20"
                >
                  <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                </svg>
              </div>
            </nav>
          </div>

          <form @submit.prevent="handleSubmit">
            <div class="p-6">
              <!-- Step 1: Cash Flow Analysis -->
              <div v-if="currentStep === 0">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Cash Flow Analysis</h3>
                <div class="space-y-6">
                  <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <div class="flex items-center space-x-3">
                      <svg class="h-8 w-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                      </svg>
                      <div>
                        <div class="text-sm text-blue-800 font-medium">Available Monthly Cash Flow</div>
                        <div class="text-2xl font-bold" :class="availableCashFlow >= 0 ? 'text-green-600' : 'text-red-600'">
                          {{ formatCurrency(availableCashFlow) }}
                        </div>
                      </div>
                    </div>
                    <p class="mt-3 text-sm text-blue-700">
                      This is your projected monthly cash flow based on recurring transactions across all accounts.
                      You can allocate a portion of this towards debt payoff and financial goals.
                    </p>
                  </div>

                  <!-- Plan Name and Description -->
                  <div>
                    <label for="name" class="block text-sm font-medium text-gray-700">Plan Name</label>
                    <input
                      id="name"
                      v-model="form.name"
                      type="text"
                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                      placeholder="e.g., 2025 Debt Payoff Plan"
                      required
                    />
                  </div>

                  <div>
                    <label for="description" class="block text-sm font-medium text-gray-700">Description (Optional)</label>
                    <textarea
                      id="description"
                      v-model="form.description"
                      rows="3"
                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                      placeholder="Add any notes about this plan..."
                    ></textarea>
                  </div>

                  <div>
                    <label for="start_date" class="block text-sm font-medium text-gray-700">Start Date</label>
                    <input
                      id="start_date"
                      v-model="form.start_date"
                      type="date"
                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                      required
                    />
                  </div>
                </div>
              </div>

              <!-- Step 2: Select Debts -->
              <div v-if="currentStep === 1">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Select Debts to Pay Off</h3>

                <div v-if="debtAccounts.length === 0" class="text-center py-12 bg-gray-50 rounded-lg">
                  <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  <h3 class="mt-2 text-sm font-medium text-gray-900">No debt accounts found</h3>
                  <p class="mt-1 text-sm text-gray-500">You don't have any liability accounts with balances.</p>
                </div>

                <div v-else class="space-y-4">
                  <div
                    v-for="account in debtAccounts"
                    :key="account.id"
                    class="border rounded-lg p-4 hover:border-indigo-300 transition"
                  >
                    <div class="flex items-start space-x-3">
                      <input
                        :id="`debt-${account.id}`"
                        type="checkbox"
                        :checked="isDebtSelected(account.id)"
                        @change="toggleDebt(account)"
                        class="mt-1 h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
                      />
                      <div class="flex-1">
                        <label :for="`debt-${account.id}`" class="block">
                          <div class="font-medium text-gray-900">{{ account.name }}</div>
                          <div class="text-sm text-gray-500 capitalize">{{ account.type }}</div>
                          <div class="mt-1 text-lg font-bold text-red-600">{{ formatCurrency(account.current_balance_cents) }}</div>
                        </label>

                        <!-- Debt Details (shown when selected) -->
                        <div v-if="isDebtSelected(account.id)" class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                          <div>
                            <label class="block text-xs font-medium text-gray-700">Interest Rate (%)</label>
                            <input
                              v-model.number="getDebt(account.id).interest_rate"
                              type="number"
                              step="0.01"
                              min="0"
                              max="100"
                              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                              placeholder="e.g., 18.99"
                              required
                            />
                          </div>
                          <div>
                            <label class="block text-xs font-medium text-gray-700">Minimum Payment</label>
                            <div class="mt-1 relative rounded-md shadow-sm">
                              <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <span class="text-gray-500 sm:text-sm">$</span>
                              </div>
                              <input
                                v-model.number="minimumPaymentDollars[account.id]"
                                type="number"
                                step="0.01"
                                min="0"
                                class="block w-full pl-7 pr-12 rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                placeholder="0.00"
                                required
                              />
                            </div>
                          </div>
                          <div>
                            <label class="block text-xs font-medium text-gray-700">Priority (if custom)</label>
                            <input
                              v-model.number="getDebt(account.id).priority"
                              type="number"
                              min="1"
                              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                              placeholder="1"
                            />
                            <p class="mt-1 text-xs text-gray-500">Lower = pay off first</p>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Step 3: Choose Strategy -->
              <div v-if="currentStep === 2">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Choose Payoff Strategy</h3>

                <div class="space-y-4">
                  <!-- Snowball Strategy -->
                  <label class="relative flex cursor-pointer rounded-lg border bg-white p-4 shadow-sm focus:outline-none">
                    <input
                      type="radio"
                      v-model="form.strategy"
                      value="snowball"
                      class="sr-only"
                    />
                    <span class="flex flex-1">
                      <span class="flex flex-col">
                        <span class="flex items-center space-x-2">
                          <span
                            class="block text-sm font-medium text-gray-900"
                            :class="form.strategy === 'snowball' ? 'text-indigo-600' : ''"
                          >
                            Debt Snowball
                          </span>
                          <span
                            v-if="form.strategy === 'snowball'"
                            class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-800"
                          >
                            Selected
                          </span>
                        </span>
                        <span class="mt-1 flex items-center text-sm text-gray-500">
                          Pay off smallest balances first for psychological wins
                        </span>
                      </span>
                    </span>
                    <svg
                      v-if="form.strategy === 'snowball'"
                      class="h-5 w-5 text-indigo-600"
                      viewBox="0 0 20 20"
                      fill="currentColor"
                    >
                      <path
                        fill-rule="evenodd"
                        d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                        clip-rule="evenodd"
                      />
                    </svg>
                  </label>

                  <!-- Avalanche Strategy -->
                  <label class="relative flex cursor-pointer rounded-lg border bg-white p-4 shadow-sm focus:outline-none">
                    <input
                      type="radio"
                      v-model="form.strategy"
                      value="avalanche"
                      class="sr-only"
                    />
                    <span class="flex flex-1">
                      <span class="flex flex-col">
                        <span class="flex items-center space-x-2">
                          <span
                            class="block text-sm font-medium text-gray-900"
                            :class="form.strategy === 'avalanche' ? 'text-indigo-600' : ''"
                          >
                            Debt Avalanche
                          </span>
                          <span
                            v-if="form.strategy === 'avalanche'"
                            class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-800"
                          >
                            Selected
                          </span>
                        </span>
                        <span class="mt-1 flex items-center text-sm text-gray-500">
                          Pay off highest interest rates first to save the most money
                        </span>
                      </span>
                    </span>
                    <svg
                      v-if="form.strategy === 'avalanche'"
                      class="h-5 w-5 text-indigo-600"
                      viewBox="0 0 20 20"
                      fill="currentColor"
                    >
                      <path
                        fill-rule="evenodd"
                        d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                        clip-rule="evenodd"
                      />
                    </svg>
                  </label>

                  <!-- Custom Strategy -->
                  <label class="relative flex cursor-pointer rounded-lg border bg-white p-4 shadow-sm focus:outline-none">
                    <input
                      type="radio"
                      v-model="form.strategy"
                      value="custom"
                      class="sr-only"
                    />
                    <span class="flex flex-1">
                      <span class="flex flex-col">
                        <span class="flex items-center space-x-2">
                          <span
                            class="block text-sm font-medium text-gray-900"
                            :class="form.strategy === 'custom' ? 'text-indigo-600' : ''"
                          >
                            Custom Priority
                          </span>
                          <span
                            v-if="form.strategy === 'custom'"
                            class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-800"
                          >
                            Selected
                          </span>
                        </span>
                        <span class="mt-1 flex items-center text-sm text-gray-500">
                          Set your own priority order for paying off debts
                        </span>
                      </span>
                    </span>
                    <svg
                      v-if="form.strategy === 'custom'"
                      class="h-5 w-5 text-indigo-600"
                      viewBox="0 0 20 20"
                      fill="currentColor"
                    >
                      <path
                        fill-rule="evenodd"
                        d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                        clip-rule="evenodd"
                      />
                    </svg>
                  </label>
                </div>

                <!-- Extra Payment Amount -->
                <div class="mt-6">
                  <label for="extra_payment" class="block text-sm font-medium text-gray-700">
                    Monthly Extra Payment Towards Debt
                  </label>
                  <div class="mt-1 relative rounded-md shadow-sm">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                      <span class="text-gray-500 sm:text-sm">$</span>
                    </div>
                    <input
                      id="extra_payment"
                      v-model.number="extraPaymentDollars"
                      type="number"
                      step="0.01"
                      min="0"
                      class="block w-full pl-7 pr-12 rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                      placeholder="0.00"
                      required
                    />
                  </div>
                  <p class="mt-2 text-sm text-gray-500">
                    Amount you can afford to put towards debt each month beyond minimum payments
                  </p>
                </div>
              </div>

              <!-- Step 4: Financial Goals -->
              <div v-if="currentStep === 3">
                <div class="flex items-center justify-between mb-4">
                  <h3 class="text-lg font-medium text-gray-900">Financial Goals (Optional)</h3>
                  <button
                    type="button"
                    @click="addGoal"
                    class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-indigo-700 bg-indigo-100 hover:bg-indigo-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                  >
                    Add Goal
                  </button>
                </div>

                <p class="text-sm text-gray-500 mb-4">
                  Set aside funds for other financial goals like vacation, emergency fund, or retirement alongside debt payoff.
                </p>

                <div v-if="form.goals.length === 0" class="text-center py-8 bg-gray-50 rounded-lg">
                  <p class="text-sm text-gray-500">No goals added yet. Click "Add Goal" to get started.</p>
                </div>

                <div v-else class="space-y-4">
                  <div
                    v-for="(goal, index) in form.goals"
                    :key="index"
                    class="border rounded-lg p-4 relative"
                  >
                    <button
                      type="button"
                      @click="removeGoal(index)"
                      class="absolute top-2 right-2 text-gray-400 hover:text-red-600"
                    >
                      <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                      </svg>
                    </button>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                      <div>
                        <label class="block text-xs font-medium text-gray-700">Goal Name</label>
                        <input
                          v-model="goal.name"
                          type="text"
                          class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                          placeholder="e.g., Vacation Fund"
                          required
                        />
                      </div>

                      <div>
                        <label class="block text-xs font-medium text-gray-700">Goal Type</label>
                        <select
                          v-model="goal.goal_type"
                          class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                          required
                        >
                          <option value="savings">Savings</option>
                          <option value="investment">Investment/Retirement</option>
                          <option value="purchase">Large Purchase</option>
                          <option value="other">Other</option>
                        </select>
                      </div>

                      <div>
                        <label class="block text-xs font-medium text-gray-700">Target Amount</label>
                        <div class="mt-1 relative rounded-md shadow-sm">
                          <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <span class="text-gray-500 sm:text-sm">$</span>
                          </div>
                          <input
                            v-model.number="goalTargetDollars[index]"
                            type="number"
                            step="0.01"
                            min="0"
                            class="block w-full pl-7 pr-12 rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                            placeholder="0.00"
                            required
                          />
                        </div>
                      </div>

                      <div>
                        <label class="block text-xs font-medium text-gray-700">Monthly Contribution</label>
                        <div class="mt-1 relative rounded-md shadow-sm">
                          <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <span class="text-gray-500 sm:text-sm">$</span>
                          </div>
                          <input
                            v-model.number="goalContributionDollars[index]"
                            type="number"
                            step="0.01"
                            min="0"
                            class="block w-full pl-7 pr-12 rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                            placeholder="0.00"
                            required
                          />
                        </div>
                      </div>

                      <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-gray-700">Target Date (Optional)</label>
                        <input
                          v-model="goal.target_date"
                          type="date"
                          class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        />
                      </div>

                      <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-gray-700">Description (Optional)</label>
                        <textarea
                          v-model="goal.description"
                          rows="2"
                          class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                          placeholder="Add notes about this goal..."
                        ></textarea>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Step 5: Review -->
              <div v-if="currentStep === 4">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Review Your Plan</h3>

                <div class="space-y-6">
                  <!-- Plan Details -->
                  <div class="bg-gray-50 rounded-lg p-4">
                    <h4 class="font-medium text-gray-900 mb-2">Plan Details</h4>
                    <dl class="grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-2">
                      <div>
                        <dt class="text-sm font-medium text-gray-500">Name</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ form.name }}</dd>
                      </div>
                      <div>
                        <dt class="text-sm font-medium text-gray-500">Strategy</dt>
                        <dd class="mt-1 text-sm text-gray-900 capitalize">{{ form.strategy }}</dd>
                      </div>
                      <div>
                        <dt class="text-sm font-medium text-gray-500">Start Date</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ formatDate(form.start_date) }}</dd>
                      </div>
                      <div>
                        <dt class="text-sm font-medium text-gray-500">Extra Payment</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ formatCurrency(form.monthly_extra_payment_cents) }}/month</dd>
                      </div>
                    </dl>
                  </div>

                  <!-- Selected Debts -->
                  <div>
                    <h4 class="font-medium text-gray-900 mb-2">Debts ({{ form.debts.length }})</h4>
                    <div class="space-y-2">
                      <div
                        v-for="debt in form.debts"
                        :key="debt.account_id"
                        class="flex items-center justify-between bg-white border rounded-lg p-3"
                      >
                        <div>
                          <div class="font-medium text-gray-900">{{ getAccountName(debt.account_id) }}</div>
                          <div class="text-sm text-gray-500">
                            {{ debt.interest_rate }}% APR • Min payment: {{ formatCurrency(debt.minimum_payment_cents) }}/mo
                          </div>
                        </div>
                        <div class="text-sm font-medium text-red-600">
                          {{ formatCurrency(getAccountBalance(debt.account_id)) }}
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Goals -->
                  <div v-if="form.goals.length > 0">
                    <h4 class="font-medium text-gray-900 mb-2">Financial Goals ({{ form.goals.length }})</h4>
                    <div class="space-y-2">
                      <div
                        v-for="(goal, index) in form.goals"
                        :key="index"
                        class="flex items-center justify-between bg-white border rounded-lg p-3"
                      >
                        <div>
                          <div class="font-medium text-gray-900">{{ goal.name }}</div>
                          <div class="text-sm text-gray-500 capitalize">
                            {{ goal.goal_type }} • {{ formatCurrency(goal.monthly_contribution_cents) }}/month
                          </div>
                        </div>
                        <div class="text-sm font-medium text-green-600">
                          {{ formatCurrency(goal.target_amount_cents) }}
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Navigation Buttons -->
            <div class="border-t border-gray-200 px-6 py-4 flex items-center justify-between bg-gray-50">
              <button
                v-if="currentStep > 0"
                type="button"
                @click="previousStep"
                class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
              >
                Previous
              </button>
              <div v-else></div>

              <button
                v-if="currentStep < steps.length - 1"
                type="button"
                @click="nextStep"
                :disabled="!canProceed"
                class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                Next
              </button>
              <button
                v-else
                type="submit"
                :disabled="form.processing"
                class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50"
              >
                Create Plan
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatCurrency } from '@/utils/format.js';

const props = defineProps({
  budget: Object,
  availableCashFlow: Number,
  debtAccounts: Array,
});

const steps = ['Plan Info', 'Select Debts', 'Strategy', 'Goals', 'Review'];
const currentStep = ref(0);

const form = useForm({
  name: '',
  description: '',
  strategy: 'avalanche',
  monthly_extra_payment_cents: 0,
  start_date: new Date().toISOString().split('T')[0],
  debts: [],
  goals: [],
});

// Helper refs for dollar amounts (easier for users)
const extraPaymentDollars = ref(0);
const minimumPaymentDollars = ref({});
const goalTargetDollars = ref({});
const goalContributionDollars = ref({});

// Watch dollar amounts and convert to cents
watch(extraPaymentDollars, (value) => {
  form.monthly_extra_payment_cents = Math.round((value || 0) * 100);
});

watch(minimumPaymentDollars, (values) => {
  form.debts.forEach(debt => {
    if (values[debt.account_id] !== undefined) {
      debt.minimum_payment_cents = Math.round((values[debt.account_id] || 0) * 100);
    }
  });
}, { deep: true });

watch(goalTargetDollars, (values) => {
  form.goals.forEach((goal, index) => {
    if (values[index] !== undefined) {
      goal.target_amount_cents = Math.round((values[index] || 0) * 100);
    }
  });
}, { deep: true });

watch(goalContributionDollars, (values) => {
  form.goals.forEach((goal, index) => {
    if (values[index] !== undefined) {
      goal.monthly_contribution_cents = Math.round((values[index] || 0) * 100);
    }
  });
}, { deep: true });

const canProceed = computed(() => {
  if (currentStep.value === 0) {
    return form.name && form.start_date;
  }
  if (currentStep.value === 1) {
    return form.debts.length > 0 && form.debts.every(d =>
      d.interest_rate >= 0 && d.minimum_payment_cents >= 0
    );
  }
  if (currentStep.value === 2) {
    return form.strategy && form.monthly_extra_payment_cents >= 0;
  }
  return true;
});

const isDebtSelected = (accountId) => {
  return form.debts.some(d => d.account_id === accountId);
};

const getDebt = (accountId) => {
  return form.debts.find(d => d.account_id === accountId);
};

const toggleDebt = (account) => {
  const index = form.debts.findIndex(d => d.account_id === account.id);
  if (index > -1) {
    form.debts.splice(index, 1);
    delete minimumPaymentDollars.value[account.id];
  } else {
    form.debts.push({
      account_id: account.id,
      interest_rate: 0,
      minimum_payment_cents: 0,
      priority: form.debts.length + 1,
    });
    minimumPaymentDollars.value[account.id] = 0;
  }
};

const addGoal = () => {
  const index = form.goals.length;
  form.goals.push({
    name: '',
    description: '',
    target_amount_cents: 0,
    monthly_contribution_cents: 0,
    target_date: null,
    goal_type: 'savings',
  });
  goalTargetDollars.value[index] = 0;
  goalContributionDollars.value[index] = 0;
};

const removeGoal = (index) => {
  form.goals.splice(index, 1);
  delete goalTargetDollars.value[index];
  delete goalContributionDollars.value[index];
};

const getAccountName = (accountId) => {
  const account = props.debtAccounts.find(a => a.id === accountId);
  return account ? account.name : 'Unknown';
};

const getAccountBalance = (accountId) => {
  const account = props.debtAccounts.find(a => a.id === accountId);
  return account ? account.current_balance_cents : 0;
};

const formatDate = (dateString) => {
  if (!dateString) return '';
  const date = new Date(dateString);
  return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
};

const nextStep = () => {
  if (canProceed.value && currentStep.value < steps.length - 1) {
    currentStep.value++;
  }
};

const previousStep = () => {
  if (currentStep.value > 0) {
    currentStep.value--;
  }
};

const handleSubmit = () => {
  form.post(route('payoff-plans.store', props.budget.id));
};
</script>