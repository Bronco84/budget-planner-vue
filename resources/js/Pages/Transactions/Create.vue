<template>
  <Head :title="'Add Transaction'" />

  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Add Transaction</h2>
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

                  <!-- Amount -->
                  <div>
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

                  <!-- Date -->
                  <div>
                    <InputLabel for="date" value="Date" />
                    <TextInput
                      id="date"
                      type="date"
                      class="mt-1 block w-full"
                      v-model="form.date"
                      required
                    />
                    <InputError class="mt-2" :message="form.errors.date" />
                  </div>

                  <!-- Link to Recurring Transaction -->
                  <div>
                    <InputLabel for="recurring_transaction_template_id" value="Link to Recurring Transaction (Optional)" />
                    <SelectInput
                      id="recurring_transaction_template_id"
                      v-model="form.recurring_transaction_template_id"
                      class="mt-1 block w-full"
                    >
                      <option value="">None (Regular Transaction)</option>
                      <option v-for="template in recurringTemplates" :key="template.id" :value="template.id">
                        {{ template.description }} ({{ template.formatted_amount }})
                      </option>
                    </SelectInput>
                    <p class="mt-1 text-xs text-gray-500">
                      Link this transaction to a recurring template to mark it as an occurrence
                    </p>
                    <InputError class="mt-2" :message="form.errors.recurring_transaction_template_id" />
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

              <!-- Note about recurring transactions -->
              <div class="border-t border-gray-200 pt-4 mt-6 text-sm text-gray-600">
                <p>
                  Need to create a recurring transaction?
                  <Link
                    :href="route('recurring-transactions.create', budget.id)"
                    class="text-indigo-600 hover:text-indigo-800"
                  >
                    Click here
                  </Link>
                  to set up a recurring transaction template instead.
                </p>
              </div>

              <div class="flex items-center justify-end mt-6">
                <Link
                  :href="route('budget.transaction.index', budget.id)"
                  class="inline-flex items-center px-4 py-2 bg-gray-300 border border-transparent rounded-md font-semibold text-xs text-gray-800 uppercase tracking-widest hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition ease-in-out duration-150"
                >
                  Cancel
                </Link>

                <PrimaryButton class="ml-3" :disabled="form.processing">
                  Create Transaction
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
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import TextArea from '@/Components/TextArea.vue';
import SelectInput from '@/Components/SelectInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { formatCurrency } from '@/utils/format.js';

const props = defineProps({
  budget: Object,
  accounts: Array,
  recurringTemplates: Array,
});

// Format today's date for input field YYYY-MM-DD
const today = new Date().toISOString().split('T')[0];

const form = useForm({
  description: '',
  amount: '',
  account_id: '',
  category: '',
  date: today,
  notes: '',
  recurring_transaction_template_id: '',
});

const submit = () => {
  form.post(route('budget.transaction.store', props.budget.id));
};
</script>
