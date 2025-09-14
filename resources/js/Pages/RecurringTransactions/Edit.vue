<template>
  <Head :title="'Edit Recurring Transaction'" />

  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
          Edit Recurring Transaction
        </h2>
        <div class="flex space-x-4">
          <Link
            :href="route('recurring-transactions.rules.index', { budget: budget.id, recurring_transaction: recurringTransaction.id })"
            class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 active:bg-blue-800 focus:outline-none focus:border-blue-900 focus:ring focus:ring-blue-300 disabled:opacity-25 transition"
          >
            <CogIcon class="w-4 h-4 mr-2" />
            Manage Rules
          </Link>
          <Link
            :href="route('recurring-transactions.index', budget.id)"
            class="inline-flex items-center px-4 py-2 bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-300 active:bg-gray-400 focus:outline-none focus:border-gray-900 focus:ring focus:ring-gray-300 disabled:opacity-25 transition"
          >
            <ArrowLeftIcon class="w-4 h-4 mr-2" />
            Back to List
          </Link>
        </div>
      </div>
    </template>

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6 bg-white border-b border-gray-200">
            <form @submit.prevent="submit">
              <div class="mb-6">
                <h3 class="text-lg font-medium text-gray-900 mb-2">Transaction Details</h3>
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
                      autofocus
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
                      required
                    />
                    <InputError class="mt-2" :message="form.errors.category" />
                  </div>

                  <!-- Amount Type -->
                  <div>
                    <InputLabel for="amount_type" value="Amount Type" />
                    <div class="mt-1 flex space-x-4">
                      <label class="inline-flex items-center">
                        <input type="radio" v-model="amountType" value="static" class="form-radio" />
                        <span class="ml-2">Static Amount</span>
                      </label>
                      <label class="inline-flex items-center">
                        <input type="radio" v-model="amountType" value="dynamic" class="form-radio" />
                        <span class="ml-2">Dynamic Amount</span>
                      </label>
                    </div>
                  </div>

                  <!-- Amount (Static) -->
                  <div v-if="amountType === 'static'">
                    <InputLabel for="amount" value="Amount" />
                    <div class="mt-1 relative rounded-md shadow-sm">
                      <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <span class="text-gray-500 sm:text-sm">$</span>
                      </div>
                      <TextInput
                        id="amount"
                        type="number"
                        step="0.01"
                        class="pl-7 block w-full"
                        v-model="form.amount"
                        required
                      />
                    </div>
                    <p class="mt-1 text-xs text-gray-500">
                      Use positive values for income, negative for expenses (e.g., -50.00)
                    </p>
                    <InputError class="mt-2" :message="form.errors.amount" />
                  </div>

                  <!-- Dynamic Amount Options -->
                  <div v-if="amountType === 'dynamic'" class="md:col-span-2 grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                      <InputLabel for="min_amount" value="Minimum Amount (Optional)" />
                      <div class="mt-1 relative rounded-md shadow-sm">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                          <span class="text-gray-500 sm:text-sm">$</span>
                        </div>
                        <TextInput
                          id="min_amount"
                          type="number"
                          step="0.01"
                          class="pl-7 block w-full"
                          v-model.number="form.min_amount"
                        />
                      </div>
                    </div>
                    <div>
                      <InputLabel for="max_amount" value="Maximum Amount (Optional)" />
                      <div class="mt-1 relative rounded-md shadow-sm">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                          <span class="text-gray-500 sm:text-sm">$</span>
                        </div>
                        <TextInput
                          id="max_amount"
                          type="number"
                          step="0.01"
                          class="pl-7 block w-full"
                          v-model.number="form.max_amount"
                        />
                      </div>
                    </div>
                    <div>
                      <InputLabel for="average_amount" value="Starting Average (Optional)" />
                      <div class="mt-1 relative rounded-md shadow-sm">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                          <span class="text-gray-500 sm:text-sm">$</span>
                        </div>
                        <TextInput
                          id="average_amount"
                          type="number"
                          step="0.01"
                          class="pl-7 block w-full"
                          v-model="form.average_amount"
                        />
                      </div>
                    </div>
                  </div>

                  <!-- Notes -->
                  <div>
                    <InputLabel for="notes" value="Notes (Optional)" />
                    <TextArea
                      id="notes"
                      class="mt-1 block w-full"
                      v-model="form.notes"
                    />
                    <InputError class="mt-2" :message="form.errors.notes" />
                  </div>
                </div>
              </div>

              <!-- Recurring Transaction Options -->
              <div class="border-t border-gray-200 pt-6 mb-6">
                <h3 class="text-lg font-medium text-gray-900 mb-2">Recurring Options</h3>

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

                  <!-- Day of week (for weekly/biweekly frequency) -->
                  <div v-if="form.frequency === 'weekly' || form.frequency === 'biweekly'">
                    <InputLabel for="day_of_week" value="Day of Week" />
                    <SelectInput
                      id="day_of_week"
                      v-model="form.day_of_week"
                      class="mt-1 block w-full"
                      required
                    >
                      <option value="0">Sunday</option>
                      <option value="1">Monday</option>
                      <option value="2">Tuesday</option>
                      <option value="3">Wednesday</option>
                      <option value="4">Thursday</option>
                      <option value="5">Friday</option>
                      <option value="6">Saturday</option>
                    </SelectInput>
                    <InputError class="mt-2" :message="form.errors.day_of_week" />
                  </div>

                  <!-- Day of month (for monthly/quarterly frequency) -->
                  <div v-if="form.frequency === 'monthly' || form.frequency === 'quarterly'">
                    <InputLabel for="day_of_month" value="Day of Month" />
                    <TextInput
                      id="day_of_month"
                      type="number"
                      min="1"
                      max="31"
                      class="mt-1 block w-full"
                      v-model="form.day_of_month"
                      required
                    />
                    <p class="mt-1 text-xs text-gray-500">
                      The day of the month when the transaction occurs (1-31)
                    </p>
                    <InputError class="mt-2" :message="form.errors.day_of_month" />
                  </div>

                  <!-- Bimonthly frequency fields -->
                  <div v-if="form.frequency === 'bimonthly'" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- First occurrence -->
                    <div>
                      <InputLabel for="first_day_of_month" value="First Occurrence" />
                      <TextInput
                        id="first_day_of_month"
                        type="number"
                        min="1"
                        max="31"
                        class="mt-1 block w-full"
                        v-model.number="form.first_day_of_month"
                        required
                      />
                      <p class="mt-1 text-xs text-gray-500">
                        First day of the month (e.g., 1 for 1st)
                      </p>
                      <InputError class="mt-2" :message="form.errors.first_day_of_month" />
                    </div>

                    <!-- Second occurrence -->
                    <div>
                      <InputLabel for="day_of_month" value="Second Occurrence" />
                      <TextInput
                        id="day_of_month"
                        type="number"
                        min="1"
                        max="31"
                        class="mt-1 block w-full"
                        v-model.number="form.day_of_month"
                        required
                      />
                      <p class="mt-1 text-xs text-gray-500">
                        Second day of the month (e.g., 15 for 15th)
                      </p>
                      <InputError class="mt-2" :message="form.errors.day_of_month" />
                    </div>
                  </div>

                  <!-- Helper text for bimonthly -->
                  <div v-if="form.frequency === 'bimonthly'" class="col-span-2 bg-blue-50 p-3 rounded-md">
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

                  <!-- End Date (Optional) -->
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

              <!-- Pattern Matching Rules (for dynamic amount) -->
              <div v-if="amountType === 'dynamic'" class="border-t border-gray-200 pt-6 mb-6">
                <div class="flex justify-between items-center mb-2">
                  <h3 class="text-lg font-medium text-gray-900">Pattern Matching Rules</h3>
                  <button
                    type="button"
                    @click="addRule"
                    class="inline-flex items-center px-3 py-1 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500"
                  >
                    Add Rule
                  </button>
                </div>

                <p class="text-sm text-gray-600 mb-4">
                  Rules determine which transactions to include when calculating the dynamic amount.
                  All rules must match for a transaction to be included.
                </p>

                <div v-if="form.rules.length === 0" class="bg-gray-50 p-4 rounded text-center text-gray-500">
                  No rules added. Click "Add Rule" to add matching criteria.
                </div>

                <div v-for="(rule, index) in form.rules" :key="index" class="mb-4 p-4 border border-gray-200 rounded-md">
                  <div class="flex justify-between mb-2">
                    <h4 class="font-medium">Rule #{{ index + 1 }}</h4>
                    <button
                      type="button"
                      @click="removeRule(index)"
                      class="text-red-600 hover:text-red-800 text-sm"
                    >
                      Remove
                    </button>
                  </div>

                  <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <!-- Field -->
                    <div>
                      <InputLabel :for="`rule_${index}_field`" value="Field" />
                      <SelectInput
                        :id="`rule_${index}_field`"
                        v-model="rule.field"
                        class="mt-1 block w-full"
                        required
                      >
                        <option value="description">Description</option>
                        <option value="amount">Amount</option>
                        <option value="category">Category</option>
                      </SelectInput>
                    </div>

                    <!-- Operator -->
                    <div>
                      <InputLabel :for="`rule_${index}_operator`" value="Operator" />
                      <SelectInput
                        :id="`rule_${index}_operator`"
                        v-model="rule.operator"
                        class="mt-1 block w-full"
                        required
                      >
                        <option value="contains">Contains</option>
                        <option value="equals">Equals</option>
                        <option value="starts_with">Starts With</option>
                        <option value="ends_with">Ends With</option>
                        <option value="regex">Regular Expression</option>
                        <option value="greater_than" v-if="rule.field === 'amount'">Greater Than</option>
                        <option value="less_than" v-if="rule.field === 'amount'">Less Than</option>
                      </SelectInput>
                    </div>

                    <!-- Value -->
                    <div>
                      <InputLabel :for="`rule_${index}_value`" value="Value" />
                      <TextInput
                        :id="`rule_${index}_value`"
                        type="text"
                        class="mt-1 block w-full"
                        v-model="rule.value"
                        required
                      />
                    </div>

                    <!-- Case Sensitive (only for text fields) -->
                    <div v-if="rule.field !== 'amount'" class="md:col-span-3">
                      <div class="flex items-center">
                        <Checkbox :id="`rule_${index}_case_sensitive`" v-model:checked="rule.is_case_sensitive" />
                        <InputLabel :for="`rule_${index}_case_sensitive`" value="Case sensitive" class="ml-2" />
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="flex items-center justify-between mt-6">
                <div>
                  <DangerButton
                    type="button"
                    class="mr-2"
                    @click="confirmDeletion"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                  >
                    Delete
                  </DangerButton>
                </div>
                <div class="flex">
                  <Link
                    :href="route('recurring-transactions.index', budget.id)"
                    class="bg-gray-100 py-2 px-4 border border-gray-300 rounded-md text-sm text-gray-700 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                  >
                    Cancel
                  </Link>
                  <PrimaryButton
                    class="ml-4"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                  >
                    Update Recurring Transaction
                  </PrimaryButton>
                </div>
              </div>
            </form>
          </div>
        </div>

        <!-- Linked Transactions -->
        <div class="mt-6 bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6 bg-white border-b border-gray-200">
            <div class="flex justify-between items-center mb-4">
              <h3 class="text-lg font-medium text-gray-900">Linked Transactions</h3>
            </div>

            <p class="text-sm text-gray-600 mb-4">
              These transactions are linked to this recurring template. You can edit them individually.
            </p>

            <div v-if="linkedTransactions.length === 0" class="bg-yellow-50 p-4 rounded-md">
              <div class="flex">
                <div class="flex-shrink-0">
                  <svg class="h-5 w-5 text-yellow-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                  </svg>
                </div>
                <div class="ml-3">
                  <h3 class="text-sm font-medium text-yellow-800">No linked transactions</h3>
                  <div class="mt-2 text-sm text-yellow-700">
                    <p>
                      There are no transactions linked to this recurring template yet.
                      Create a new transaction and select this template to link it.
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <div v-else class="overflow-x-auto">
              <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                  <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Date
                    </th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Description
                    </th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Account
                    </th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Category
                    </th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Amount
                    </th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                      Actions
                    </th>
                  </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                  <tr v-for="transaction in linkedTransactions" :key="transaction.id">
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      {{ formatDate(transaction.date) }}
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-900">
                      {{ transaction.description }}
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500">
                      {{ transaction.account.name }}
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500">
                      {{ transaction.category }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right"
                        :class="{ 'text-green-600': transaction.amount_in_cents > 0, 'text-red-600': transaction.amount_in_cents < 0 }">
                      {{ formatAmount(transaction.amount_in_cents) }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                      <Link :href="route('budget.transaction.edit', [budget.id, transaction.id])" class="text-indigo-600 hover:text-indigo-900">
                        Edit
                      </Link>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div class="mt-4 flex justify-end">
              <Link :href="route('budget.transaction.create', budget.id)" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 active:bg-indigo-800 focus:outline-none focus:border-indigo-900 focus:ring focus:ring-indigo-300 disabled:opacity-25 transition">
                Create New Transaction
              </Link>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <Modal :show="deleteModalOpen" @close="closeModal">
      <div class="p-6">
        <h2 class="text-lg font-medium text-gray-900">
          Are you sure you want to delete this recurring transaction?
        </h2>
        <p class="mt-1 text-sm text-gray-600">
          This action cannot be undone.
        </p>

        <div class="mt-6 flex justify-end">
          <SecondaryButton @click="closeModal">
            Cancel
          </SecondaryButton>

          <DangerButton
            class="ml-3"
            :class="{ 'opacity-25': deleting }"
            :disabled="deleting"
            @click="deleteTransaction"
          >
            Delete Recurring Transaction
          </DangerButton>
        </div>
      </div>
    </Modal>
  </AuthenticatedLayout>
</template>

<script setup>
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import TextArea from '@/Components/TextArea.vue';
import SelectInput from '@/Components/SelectInput.vue';
import Modal from '@/Components/Modal.vue';
import Checkbox from '@/Components/Checkbox.vue';
import { Cog6ToothIcon as CogIcon, ArrowLeftIcon } from '@heroicons/vue/24/solid';

// Define props
const props = defineProps({
  budget: Object,
  accounts: Array,
  recurringTransaction: Object,
  rules: Array, // Rules for the recurring template if any
  linkedTransactions: Array,
});

// UI state
const deleteModalOpen = ref(false);
const deleting = ref(false);
const amountType = ref('static');

// Formatting functions
const formatDate = (dateString) => {
  if (!dateString) return '';
  const date = new Date(dateString);
  return date.toLocaleDateString();
};

const formatDateForInput = (dateString) => {
  if (!dateString) return '';
  return new Date(dateString).toISOString().substr(0, 10);
};

const formatAmount = (amountInCents) => {
  const amount = Math.abs(amountInCents / 100);
  return '$' + amount.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
};

const confirmDeletion = () => {
  deleteModalOpen.value = true;
};

const closeModal = () => {
  deleteModalOpen.value = false;
};

const deleteTransaction = () => {
  deleting.value = true;
  router.delete(route('recurring-transactions.destroy', [props.budget.id, props.recurringTransaction.id]), {
    onFinish: () => {
      deleting.value = false;
      closeModal();
    },
  });
};

// On mount, initialize form based on existing data
onMounted(() => {
  if (props.recurringTransaction.is_dynamic_amount) {
    amountType.value = 'dynamic';
  }
});

// Setup form with initial values from props
const form = useForm({
  description: props.recurringTransaction.description,
  amount: (props.recurringTransaction.amount_in_cents / 100).toFixed(2),
  account_id: props.recurringTransaction.account_id,
  category: props.recurringTransaction.category,
  frequency: props.recurringTransaction.frequency,
  day_of_month: props.recurringTransaction.day_of_month || 1,
  day_of_week: props.recurringTransaction.day_of_week?.toString() || '',
  first_day_of_month: props.recurringTransaction.first_day_of_month || 1,
  start_date: formatDateForInput(props.recurringTransaction.start_date),
  end_date: formatDateForInput(props.recurringTransaction.end_date) || '',
  min_amount: props.recurringTransaction.min_amount ?
    (props.recurringTransaction.min_amount / 100).toFixed(2) : '',
  max_amount: props.recurringTransaction.max_amount ?
    (props.recurringTransaction.max_amount / 100).toFixed(2) : '',
  average_amount: props.recurringTransaction.average_amount ?
    props.recurringTransaction.average_amount.toFixed(2) : '',
  notes: props.recurringTransaction.notes || '',

  // Rules for dynamic amount
  rules: props.rules?.map(rule => ({
    id: rule.id,
    field: rule.field,
    operator: rule.operator,
    value: rule.value,
    is_case_sensitive: rule.is_case_sensitive,
  })) || [],
});

// Rule management
const addRule = () => {
  form.rules.push({
    field: 'description',
    operator: 'contains',
    value: '',
    is_case_sensitive: false,
  });
};

const removeRule = (index) => {
  form.rules.splice(index, 1);
};

// Submit form
const submit = () => {
  // Update values before submitting
  form.is_dynamic_amount = amountType.value === 'dynamic';

  // Debug form data before submission
  console.log('Submitting form data with rules:', {
    is_dynamic_amount: form.is_dynamic_amount,
    rules: form.rules,
  });

    form.transform((form) => ({
        ...form,
        is_dynamic_amount: amountType.value === 'dynamic'
    })).patch(route('recurring-transactions.update', [props.budget.id, props.recurringTransaction.id]), {
        onSuccess: () => {
            console.log('Form submitted successfully');
        },
        onError: (errors) => {
            console.error('Form submission errors:', errors);
        }
    });
};
</script>
