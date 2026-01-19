<template>
  <Head title="Edit Transfer" />

  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <div class="flex items-center gap-3">
          <div class="p-2 bg-cyan-100 dark:bg-cyan-900 rounded-lg">
            <ArrowsRightLeftIcon class="w-5 h-5 text-cyan-600 dark:text-cyan-400" />
          </div>
          <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Edit Transfer
          </h2>
        </div>
      </div>
    </template>

    <div class="py-12">
      <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <form @submit.prevent="submit">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- From Account -->
                <div>
                  <InputLabel for="from_account_id" value="From Account" />
                  <SelectInput
                    id="from_account_id"
                    v-model="form.from_account_id"
                    class="mt-1 block w-full"
                    required
                  >
                    <option value="" disabled>Select source account</option>
                    <option v-for="account in fromAccountOptions" :key="account.id" :value="account.id">
                      {{ account.name }}
                    </option>
                  </SelectInput>
                  <InputError :message="form.errors.from_account_id" class="mt-2" />
                </div>

                <!-- To Account -->
                <div>
                  <InputLabel for="to_account_id" value="To Account" />
                  <SelectInput
                    id="to_account_id"
                    v-model="form.to_account_id"
                    class="mt-1 block w-full"
                    required
                  >
                    <option value="" disabled>Select destination account</option>
                    <option v-for="account in toAccountOptions" :key="account.id" :value="account.id">
                      {{ account.name }}
                    </option>
                  </SelectInput>
                  <InputError :message="form.errors.to_account_id" class="mt-2" />
                </div>

                <!-- Amount -->
                <div>
                  <InputLabel for="amount" value="Amount" />
                  <div class="mt-1 relative rounded-md shadow-sm">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                      <span class="text-gray-500 dark:text-gray-400 sm:text-sm">$</span>
                    </div>
                    <TextInput
                      id="amount"
                      v-model="form.amount"
                      type="number"
                      step="0.01"
                      min="0.01"
                      class="pl-7 block w-full"
                      placeholder="0.00"
                      required
                    />
                  </div>
                  <InputError :message="form.errors.amount" class="mt-2" />
                </div>

                <!-- Date -->
                <div>
                  <InputLabel for="date" value="Date" />
                  <TextInput
                    id="date"
                    v-model="form.date"
                    type="date"
                    class="mt-1 block w-full"
                    required
                  />
                  <InputError :message="form.errors.date" class="mt-2" />
                </div>

                <!-- Description -->
                <div class="md:col-span-2">
                  <InputLabel for="description" value="Description (optional)" />
                  <TextInput
                    id="description"
                    v-model="form.description"
                    type="text"
                    class="mt-1 block w-full"
                    placeholder="e.g., Monthly savings transfer"
                  />
                  <InputError :message="form.errors.description" class="mt-2" />
                </div>

                <!-- Notes -->
                <div class="md:col-span-2">
                  <InputLabel for="notes" value="Notes (optional)" />
                  <textarea
                    id="notes"
                    v-model="form.notes"
                    class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm"
                    rows="3"
                    placeholder="Additional notes..."
                  ></textarea>
                  <InputError :message="form.errors.notes" class="mt-2" />
                </div>
              </div>

              <!-- Transfer Preview -->
              <div v-if="form.from_account_id && form.to_account_id && form.amount" class="mt-6 p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                <div class="flex items-center justify-center gap-4 text-sm">
                  <span class="font-medium text-gray-700 dark:text-gray-300">
                    {{ accounts.find(a => a.id === parseInt(form.from_account_id))?.name }}
                  </span>
                  <div class="flex items-center gap-2 text-cyan-600 dark:text-cyan-400">
                    <span class="text-xl font-bold">${{ parseFloat(form.amount || 0).toFixed(2) }}</span>
                    <ArrowsRightLeftIcon class="w-6 h-6" />
                  </div>
                  <span class="font-medium text-gray-700 dark:text-gray-300">
                    {{ accounts.find(a => a.id === parseInt(form.to_account_id))?.name }}
                  </span>
                </div>
              </div>

              <!-- Action Buttons -->
              <div class="flex items-center justify-between mt-8 pt-6 border-t border-gray-200 dark:border-gray-700">
                <button
                  type="button"
                  @click="confirmDelete"
                  class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-500 active:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150"
                >
                  Delete Transfer
                </button>

                <div class="flex justify-end space-x-3">
                  <Link
                    :href="route('budgets.show', budget.id)"
                    class="inline-flex items-center px-4 py-2 bg-gray-300 dark:bg-gray-600 border border-transparent rounded-md font-semibold text-xs text-gray-800 dark:text-gray-200 uppercase tracking-widest hover:bg-gray-400 dark:hover:bg-gray-500 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition ease-in-out duration-150"
                  >
                    Cancel
                  </Link>

                  <PrimaryButton type="submit" :disabled="form.processing">
                    Update Transfer
                  </PrimaryButton>
                </div>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import SelectInput from '@/Components/SelectInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { ArrowsRightLeftIcon } from '@heroicons/vue/24/outline';
import { useToast } from '@/composables/useToast';

const toast = useToast();

const props = defineProps({
  budget: Object,
  transfer: Object,
  accounts: Array,
});

// Initialize form with transfer data
const form = useForm({
  from_account_id: props.transfer.from_account_id,
  to_account_id: props.transfer.to_account_id,
  amount: (props.transfer.amount_in_cents / 100).toFixed(2),
  date: props.transfer.date,
  description: props.transfer.description || '',
  notes: props.transfer.notes || '',
});

// Computed property for filtered "to" accounts (exclude selected "from" account)
const toAccountOptions = computed(() => {
  if (!form.from_account_id) return props.accounts;
  return props.accounts.filter(account => account.id !== parseInt(form.from_account_id));
});

// Computed property for filtered "from" accounts (exclude selected "to" account)
const fromAccountOptions = computed(() => {
  if (!form.to_account_id) return props.accounts;
  return props.accounts.filter(account => account.id !== parseInt(form.to_account_id));
});

// Form submission
const submit = () => {
  form.patch(route('budget.transfers.update', [props.budget.id, props.transfer.id]), {
    onSuccess: () => {
      toast.success('Transfer updated successfully');
    },
  });
};

// Delete confirmation
const confirmDelete = async () => {
  const confirmed = await toast.confirm({
    title: 'Delete Transfer',
    message: 'Are you sure you want to delete this transfer? This will also delete the associated transactions.',
    confirmText: 'Delete',
    cancelText: 'Cancel',
    type: 'danger'
  });

  if (confirmed) {
    router.delete(route('budget.transfers.destroy', [props.budget.id, props.transfer.id]), {
      onSuccess: () => {
        toast.success('Transfer deleted successfully');
      },
    });
  }
};
</script>
