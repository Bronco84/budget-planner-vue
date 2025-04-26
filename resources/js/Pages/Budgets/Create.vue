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
              
              <div class="mb-4">
                <InputLabel for="total_amount" value="Total Budget Amount" />
                <div class="relative mt-1 rounded-md shadow-sm">
                  <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                    <span class="text-gray-500 sm:text-sm">$</span>
                  </div>
                  <TextInput
                    id="total_amount"
                    type="number"
                    step="0.01"
                    min="0"
                    class="mt-1 block w-full pl-7"
                    v-model="form.total_amount"
                    required
                  />
                </div>
                <InputError class="mt-2" :message="form.errors.total_amount" />
              </div>
              
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
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
                
                <div>
                  <InputLabel for="end_date" value="End Date" />
                  <TextInput
                    id="end_date"
                    type="date"
                    class="mt-1 block w-full"
                    v-model="form.end_date"
                    required
                  />
                  <InputError class="mt-2" :message="form.errors.end_date" />
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

// Initialize form with default values
const form = useForm({
  name: '',
  description: '',
  total_amount: '',
  start_date: new Date().toISOString().split('T')[0], // Today
  end_date: new Date(new Date().setMonth(new Date().getMonth() + 1)).toISOString().split('T')[0], // One month from today
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