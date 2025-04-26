<template>
  <Head title="Edit Account" />

  <AuthenticatedLayout>
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 leading-tight">Edit Account</h2>
    </template>

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6 text-gray-900">
            <div class="mb-6">
              <h3 class="text-lg font-medium">Account Details</h3>
              <p class="mt-1 text-sm text-gray-600">
                Update the information for this account.
              </p>
            </div>
            
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
                  <option value="investment">Investment</option>
                  <option value="other">Other</option>
                </SelectInput>
                <InputError class="mt-2" :message="form.errors.type" />
              </div>
              
              <div class="mb-6">
                <div class="flex items-start">
                  <div class="flex h-5 items-center">
                    <Checkbox id="include_in_budget" v-model:checked="form.include_in_budget" />
                  </div>
                  <div class="ml-3 text-sm">
                    <label for="include_in_budget" class="font-medium text-gray-700">Include in Budget Calculations</label>
                    <p class="text-gray-500">Whether this account's balance should be included in your total budget calculations.</p>
                  </div>
                </div>
              </div>
              
              <div class="flex items-center justify-end mt-6 space-x-4">
                <Link
                  :href="route('budgets.show', budget.id)"
                  class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150"
                >
                  Cancel
                </Link>
                
                <button 
                  type="button" 
                  @click="confirmAccountDeletion" 
                  class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-500 active:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150"
                >
                  Delete Account
                </button>
                
                <PrimaryButton :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                  Update Account
                </PrimaryButton>
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
          Are you sure you want to delete this account?
        </h2>

        <p class="mt-1 text-sm text-gray-600">
          Deleting this account is only possible if it has no transactions. If you proceed, the account will be permanently removed from your budget.
        </p>

        <div class="mt-6 flex justify-end">
          <SecondaryButton @click="closeModal">
            Cancel
          </SecondaryButton>

          <DangerButton
            class="ml-3"
            :class="{ 'opacity-25': deleting }"
            :disabled="deleting"
            @click="deleteAccount"
          >
            Delete Account
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
import TextInput from '@/Components/TextInput.vue';
import SelectInput from '@/Components/SelectInput.vue';
import Checkbox from '@/Components/Checkbox.vue';
import Modal from '@/Components/Modal.vue';

// Define props to receive the budget and account
const props = defineProps({
  budget: Object,
  account: Object
});

// Initialize form with account values
const form = useForm({
  name: props.account.name,
  type: props.account.type,
  include_in_budget: props.account.include_in_budget,
});

// Submit form handler
const submit = () => {
  form.put(route('budgets.accounts.update', [props.budget.id, props.account.id]), {
    preserveScroll: true,
  });
};

// Delete account functionality
const confirmingDeletion = ref(false);
const deleting = ref(false);

const confirmAccountDeletion = () => {
  confirmingDeletion.value = true;
};

const closeModal = () => {
  confirmingDeletion.value = false;
};

const deleteAccount = () => {
  deleting.value = true;
  
  form.delete(route('budgets.accounts.destroy', [props.budget.id, props.account.id]), {
    onFinish: () => {
      deleting.value = false;
      closeModal();
    },
  });
};
</script> 