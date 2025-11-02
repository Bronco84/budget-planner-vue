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
                  <option value="line of credit">Line of Credit</option>
                  <option value="mortgage">Mortgage</option>
                  <option value="investment">Investment</option>
                  <option value="loan">Loan</option>
                  <option value="other">Other</option>
                </SelectInput>
                <InputError class="mt-2" :message="form.errors.type" />
              </div>
              
              <div class="mb-6">
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
                  <p class="text-sm text-gray-500 mt-1">
                    This determines whether this account's balance will be included in your total budget calculations.
                  </p>
                </div>
              </div>

              <div class="mb-6">
                <InputLabel for="total_balance_setting" value="Total Balance Display" />
                <div class="mt-2">
                  <div class="flex items-center">
                    <input
                      id="exclude-from-total-balance"
                      type="checkbox"
                      v-model="form.exclude_from_total_balance"
                      class="h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500 rounded"
                    />
                    <label for="exclude-from-total-balance" class="ml-2 block text-sm text-gray-700">
                      Exclude from total balance calculation
                    </label>
                  </div>
                  <p class="text-sm text-gray-500 mt-1">
                    When checked, this account's balance will not be included in the total balance shown at the top of your budget. Useful for accounts you want to track but not include in your net worth calculation.
                  </p>
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
            
            <!-- Plaid Connection Section -->
            <div class="mt-10 pt-6 border-t border-gray-200">
              <h3 class="text-lg font-medium text-gray-900 mb-4">Bank Connection</h3>
              <p class="text-sm text-gray-600 mb-4">
                Connect this account to your bank to automatically import transactions and update balances.
              </p>
              
              <div v-if="account.plaid_account" class="bg-blue-50 p-4 rounded-md border border-blue-200 mb-4">
                <div class="flex justify-between items-start">
                  <div>
                    <p class="font-medium text-blue-800">{{ account.plaid_account.institution_name }}</p>
                    <p class="text-sm text-blue-600 mt-1">
                      Last synced: <PlaidSyncTimestamp
                        :timestamp="account.plaid_account?.plaid_connection?.last_sync_at"
                        format="absolute"
                      />
                    </p>
                  </div>
                  <div>
                    <Link 
                      :href="route('plaid.link', [budget.id, account.id])"
                      class="inline-flex items-center px-3 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-500"
                    >
                      Manage Connection
                    </Link>
                  </div>
                </div>
              </div>
              
              <div v-else>
                <Link 
                  :href="route('plaid.link', [budget.id, account.id])"
                  class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500"
                >
                  Connect to Bank
                </Link>
              </div>
            </div>
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
import { ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import SelectInput from '@/Components/SelectInput.vue';
import Modal from '@/Components/Modal.vue';
import PlaidSyncTimestamp from '@/Components/PlaidSyncTimestamp.vue';

// Define props to receive the budget and account
const props = defineProps({
  budget: Object,
  account: Object
});

// Set up account status based on include_in_budget
const accountStatus = ref(props.account.include_in_budget ? 'active' : 'excluded');

// Initialize form with account values
const form = useForm({
  name: props.account.name,
  type: props.account.type,
  include_in_budget: props.account.include_in_budget,
  exclude_from_total_balance: props.account.exclude_from_total_balance,
});

// Watch for changes to account status and update include_in_budget
watch(accountStatus, (newValue) => {
  form.include_in_budget = newValue === 'active';
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