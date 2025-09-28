<template>
  <Head title="Add Account" />

  <AuthenticatedLayout>
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 leading-tight">Add Account</h2>
    </template>

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6 text-gray-900">
            <form @submit.prevent="submit">
              <div class="mb-4">
                <InputLabel for="name" value="Account Name" required />
                <TextInput
                  id="name"
                  type="text"
                  class="mt-1 block w-full"
                  v-model="form.name"
                  required
                  autofocus
                  placeholder="Name of the account"
                />
                <InputError class="mt-2" :message="form.errors.name" />
              </div>
              
              <div class="mb-4">
                <InputLabel for="type" value="Account Type" required />
                <SelectInput
                  id="type"
                  class="mt-1 block w-full"
                  v-model="form.type"
                  required
                >
                  <option value="checking">Checking</option>
                  <option value="savings">Savings</option>
                  <option value="credit">Credit Card</option>
                  <option value="line of credit">Line of Credit</option>
                  <option value="mortgage">Mortgage</option>
                  <option value="investment">Investment</option>
                  <option value="loan">Loan</option>
                  <option value="other">Other</option>
                </SelectInput>
                <InputError class="mt-2" :message="form.errors.type" />
              </div>
              
              <div class="mb-4">
                <InputLabel for="current_balance" value="Current Balance" required />
                <div class="relative mt-1 rounded-md shadow-sm">
                  <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                    <span class="text-gray-500 sm:text-sm">$</span>
                  </div>
                  <TextInput
                    id="current_balance"
                    type="number"
                    step="0.01"
                    class="mt-1 block w-full pl-7"
                    v-model="form.current_balance"
                    required
                  />
                </div>
                <InputError class="mt-2" :message="form.errors.current_balance" />
              </div>
              
              <div class="mb-4">
                <InputLabel for="status" value="Account Status" />
                <div class="mt-2">
                  <div class="flex items-center">
                    <input
                      id="status-active"
                      type="radio"
                      name="status"
                      value="active"
                      v-model="accountStatus"
                      class="h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500"
                    />
                    <label for="status-active" class="ml-2 block text-sm text-gray-700">
                      Active (include in budget calculations)
                    </label>
                  </div>
                  <div class="flex items-center mt-2">
                    <input
                      id="status-excluded"
                      type="radio"
                      name="status"
                      value="excluded"
                      v-model="accountStatus"
                      class="h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500"
                    />
                    <label for="status-excluded" class="ml-2 block text-sm text-gray-700">
                      Excluded (don't include in budget calculations)
                    </label>
                  </div>
                </div>
              </div>
              
              <div class="flex items-center justify-end mt-6">
                <Link
                  :href="route('budgets.show', budget.id)"
                  class="px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150 mr-3"
                >
                  Cancel
                </Link>
                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                  Create Account
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
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import SelectInput from '@/Components/SelectInput.vue';
import { ref, watch } from 'vue';

// Define props to receive the budget
const props = defineProps({
  budget: Object
});

// Set up account status
const accountStatus = ref('active');

// Initialize form with default values
const form = useForm({
  name: '',
  type: 'checking',
  current_balance: 0,
  include_in_budget: true
});

// Watch for changes to account status and update include_in_budget
watch(accountStatus, (newValue) => {
  form.include_in_budget = newValue === 'active';
});

// Submit form handler
const submit = () => {
  form.post(route('budgets.accounts.store', props.budget.id), {
    onSuccess: () => {
      form.reset();
      accountStatus.value = 'active';
    },
  });
};
</script> 