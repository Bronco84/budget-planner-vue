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

              <!-- Color Picker -->
              <div class="mb-4">
                <InputLabel for="color" value="Budget Color" />
                <div class="mt-2 flex items-center gap-4">
                  <input
                    id="color"
                    type="color"
                    v-model="form.color"
                    class="h-10 w-20 rounded border border-gray-300 cursor-pointer"
                  />
                  
                  <div class="flex items-center gap-3">
                    <div 
                      class="w-12 h-12 rounded-full flex items-center justify-center text-white font-bold text-sm select-none"
                      :style="{ backgroundColor: form.color, lineHeight: 1 }"
                    >
                      {{ getInitials(form.name) }}
                    </div>
                    <div class="text-sm text-gray-600">Preview</div>
                  </div>

                  <div class="flex gap-2 flex-wrap">
                    <button
                      v-for="presetColor in presetColors"
                      :key="presetColor"
                      type="button"
                      @click="form.color = presetColor"
                      :class="[
                        'w-8 h-8 rounded-full border-2 transition-all',
                        form.color === presetColor ? 'border-gray-900 scale-110' : 'border-gray-300'
                      ]"
                      :style="{ backgroundColor: presetColor }"
                    ></button>
                  </div>
                </div>
                <InputError class="mt-2" :message="form.errors.color" />
              </div>
              
              <div class="mb-8">
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                  <div class="flex">
                    <div class="flex-shrink-0">
                      <svg class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                      </svg>
                    </div>
                    <div class="ml-3">
                      <h3 class="text-sm font-medium text-blue-800">What's next?</h3>
                      <p class="mt-1 text-sm text-blue-700">
                        After creating your budget, you can connect your bank accounts for automatic transaction syncing, or add accounts manually for full control.
                      </p>
                    </div>
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

const presetColors = [
  '#3b82f6', '#8b5cf6', '#ec4899', '#6366f1', '#06b6d4', '#14b8a6',
  '#10b981', '#22c55e', '#f59e0b', '#f97316', '#ef4444', '#f43f5e',
];

const getInitials = (name) => {
  if (!name) return '?';
  const words = name.trim().split(/\s+/);
  if (words.length === 1) {
    return words[0].substring(0, 2).toUpperCase();
  }
  return (words[0][0] + words[1][0]).toUpperCase();
};

// Initialize form with default values
const form = useForm({
  name: '',
  description: '',
  color: '#6366f1',
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