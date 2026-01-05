<template>
  <Head title="Edit Budget" />

  <AuthenticatedLayout>
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 leading-tight">Edit Budget</h2>
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
                  <!-- Color Input -->
                  <div class="relative">
                    <input
                      id="color"
                      type="color"
                      v-model="form.color"
                      class="h-10 w-20 rounded border border-gray-300 dark:border-gray-600 cursor-pointer"
                    />
                  </div>
                  
                  <!-- Preview with Initials -->
                  <div class="flex items-center gap-3">
                    <div 
                      class="w-12 h-12 rounded-full flex items-center justify-center text-white font-bold text-sm select-none"
                      :style="{ backgroundColor: form.color || '#6366f1', lineHeight: 1 }"
                    >
                      {{ getInitials(form.name) }}
                    </div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">
                      Preview
                    </div>
                  </div>

                  <!-- Preset Colors -->
                  <div class="flex gap-2 flex-wrap">
                    <button
                      v-for="presetColor in presetColors"
                      :key="presetColor"
                      type="button"
                      @click="form.color = presetColor"
                      :class="[
                        'w-8 h-8 rounded-full border-2 transition-all',
                        form.color === presetColor ? 'border-gray-900 dark:border-white scale-110' : 'border-gray-300 dark:border-gray-600'
                      ]"
                      :style="{ backgroundColor: presetColor }"
                      :title="presetColor"
                    ></button>
                  </div>
                </div>
                <InputError class="mt-2" :message="form.errors.color" />
              </div>
              
              
              <div class="flex items-center justify-between mt-6">
                <DeleteButton @click="confirmBudgetDeletion" class="mr-auto">
                  Delete Budget
                </DeleteButton>
                
                <div class="flex items-center space-x-3">
                  <Link
                    :href="route('budgets.show', budget.id)"
                    class="px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150"
                  >
                    Cancel
                  </Link>
                  <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Update Budget
                  </PrimaryButton>
                </div>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <Modal :show="confirmingDeletion" @close="closeModal">
      <div class="p-6">
        <h2 class="text-lg font-medium text-gray-900">
          Are you sure you want to delete this budget?
        </h2>

        <p class="mt-1 text-sm text-gray-600">
          Once this budget is deleted, all of its resources and data will be permanently deleted.
        </p>

        <div class="mt-6 flex justify-end">
          <SecondaryButton @click="closeModal">
            Cancel
          </SecondaryButton>

          <DangerButton
            class="ml-3"
            :class="{ 'opacity-25': form.processing }"
            :disabled="form.processing"
            @click="deleteBudget"
          >
            Delete Budget
          </DangerButton>
        </div>
      </div>
    </Modal>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import DeleteButton from '@/Components/DeleteButton.vue';
import TextArea from '@/Components/TextArea.vue';
import TextInput from '@/Components/TextInput.vue';
import Modal from '@/Components/Modal.vue';

// Define props
const props = defineProps({
  budget: Object,
});

// Preset color options
const presetColors = [
  '#3b82f6', // blue
  '#8b5cf6', // purple
  '#ec4899', // pink
  '#6366f1', // indigo
  '#06b6d4', // cyan
  '#14b8a6', // teal
  '#10b981', // emerald
  '#22c55e', // green
  '#f59e0b', // amber
  '#f97316', // orange
  '#ef4444', // red
  '#f43f5e', // rose
];

// Generate initials from budget name
const getInitials = (name) => {
  if (!name) return '?';
  
  const words = name.trim().split(/\s+/);
  if (words.length === 1) {
    return words[0].substring(0, 2).toUpperCase();
  }
  return (words[0][0] + words[1][0]).toUpperCase();
};

// Initialize form with budget data
const form = useForm({
  name: props.budget.name,
  description: props.budget.description || '',
  color: props.budget.color || '#6366f1',
});

// Submit form handler
const submit = () => {
  form.patch(route('budgets.update', props.budget.id));
};

// Delete confirmation state
const confirmingDeletion = ref(false);

const confirmBudgetDeletion = () => {
  confirmingDeletion.value = true;
};

const closeModal = () => {
  confirmingDeletion.value = false;
};

// Delete budget handler
const deleteBudget = () => {
  form.delete(route('budgets.destroy', props.budget.id), {
    preserveScroll: true,
    onSuccess: () => closeModal(),
  });
};
</script> 