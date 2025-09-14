<template>
  <Head :title="'Add Recurring Transaction'" />

  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Add Recurring Transaction</h2>
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
                    <InputError class="mt-2" :message="form.errors.is_dynamic_amount" />
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
                          v-model="form.min_amount"
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
                          v-model="form.max_amount"
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
                      v-model.number="form.day_of_month"
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

              <div class="flex items-center justify-end mt-6">
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
                  Create Recurring Transaction
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
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import TextArea from '@/Components/TextArea.vue';
import SelectInput from '@/Components/SelectInput.vue';
import Checkbox from '@/Components/Checkbox.vue';
import { formatCurrency } from '@/utils/format.js';

const props = defineProps({
  budget: Object,
  accounts: Array,
});

// UI state
const amountType = ref('static');

// Today's date for default start date
const today = new Date().toISOString().substring(0, 10);

const form = useForm({
  description: '',
  amount: '',
  account_id: '',
  category: '',
  frequency: '',
  day_of_month: 1,
  day_of_week: new Date().getDay().toString(),
  first_day_of_month: 1,
  start_date: today,
  end_date: '',
  min_amount: '',
  max_amount: '',
  average_amount: '',
  notes: '',
  rules: [],
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

const submit = () => {
  // Update values before submitting
  form.is_dynamic_amount = amountType.value === 'dynamic';

  console.log('Submitting form data:', {
    ...form.data(),
    is_dynamic_amount: amountType.value === 'dynamic'
  });

  form.transform((form) => ({
      ...form,
      is_dynamic_amount: amountType.value === 'dynamic'
  })).post(route('recurring-transactions.store', props.budget.id), {
    onSuccess: () => {
      console.log('Form submitted successfully');
    },
    onError: (errors) => {
      console.error('Form submission errors:', errors);
    },
    onFinish: () => {
      console.log('Form submission finished');
    }
  });
};
</script>
