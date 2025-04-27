<template>
  <Head title="Create Budget" />

  <AuthenticatedLayout>
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 leading-tight">Create Budget</h2>
    </template>

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6 text-gray-900">
            <form @submit.prevent="submit">
              <div class="mb-4">
                <InputLabel for="name" value="Budget Name" />
                <TextInput
                  id="name"
                  type="text"
                  class="mt-1 block w-full"
                  v-model="form.name"
                  required
                  autofocus
                />
                <InputError class="mt-2" :message="form.errors.name" />
              </div>
              
              <div class="mb-4">
                <InputLabel for="description" value="Description (Optional)" />
                <TextArea
                  id="description"
                  class="mt-1 block w-full"
                  v-model="form.description"
                  rows="3"
                />
                <InputError class="mt-2" :message="form.errors.description" />
              </div>
              
              <div class="mb-8">
                <h3 class="text-lg font-medium text-gray-900 mb-2">Initial Account</h3>
                <p class="mb-4 text-sm text-gray-600">Your budget needs at least one account to track your finances. You can add more accounts later.</p>
                
                <div class="bg-gray-50 p-4 rounded-lg">
                  <div class="mb-4">
                    <InputLabel for="account_name" value="Account Name" />
                    <TextInput
                      id="account_name"
                      type="text"
                      class="mt-1 block w-full"
                      v-model="form.account_name"
                      required
                      placeholder="e.g., Checking, Credit Card, etc."
                    />
                    <InputError class="mt-2" :message="form.errors.account_name" />
                  </div>
                  
                  <div class="mb-4">
                    <InputLabel for="account_type" value="Account Type" />
                    <SelectInput
                      id="account_type"
                      class="mt-1 block w-full"
                      v-model="form.account_type"
                      required
                    >
                      <option value="checking">Checking</option>
                      <option value="savings">Savings</option>
                      <option value="credit">Credit Card</option>
                      <option value="investment">Investment</option>
                      <option value="other">Other</option>
                    </SelectInput>
                    <InputError class="mt-2" :message="form.errors.account_type" />
                  </div>
                  
                  <div class="mb-0">
                    <InputLabel for="starting_balance" value="Starting Balance" />
                    <div class="relative mt-1 rounded-md shadow-sm">
                      <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                        <span class="text-gray-500 sm:text-sm">$</span>
                      </div>
                      <TextInput
                        id="starting_balance"
                        type="number"
                        step="0.01"
                        class="mt-1 block w-full pl-7"
                        v-model="form.starting_balance"
                        required
                      />
                    </div>
                    <InputError class="mt-2" :message="form.errors.starting_balance" />
                  </div>
                </div>
              </div>
              
              <div class="flex items-center justify-end mt-6">
                <Link
                  :href="route('budgets.index')"
                  class="px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150 mr-3"
                >
                  Cancel
                </Link>
                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                  Create Budget
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
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import SelectInput from '@/Components/SelectInput.vue';

// Initialize form with default values
const form = useForm({
  name: '',
  description: '',
  account_name: '',
  account_type: 'checking',
  starting_balance: 0
});

// Submit form handler
const submit = () => {
  form.post(route('budgets.store'), {
    onSuccess: () => {
      form.reset();
    },
  });
};
</script> 