<template>
  <Head :title="'Edit Transaction'" />

  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
          Edit Transaction
        </h2>
      </div>
    </template>

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6 bg-white border-b border-gray-200">
            <form @submit.prevent="submit">
              <div class="mb-6">
                <div class="flex items-center justify-between mb-4">
                  <h3 class="text-lg font-medium text-gray-900">Transaction Details</h3>
                  <!-- Recurring transaction indicator -->
                  <div v-if="recurringTemplate" class="flex items-center space-x-3">
                    <div class="inline-flex items-center px-3 py-2 text-sm font-medium bg-purple-100 text-purple-800 rounded-lg">
                      <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                      </svg>
                      Linked to Recurring Transaction
                    </div>
                    <Link 
                      :href="route('recurring-transactions.edit', [budget.id, recurringTemplate.id])"
                      class="inline-flex items-center px-3 py-2 text-sm text-purple-600 bg-purple-50 hover:bg-purple-100 hover:text-purple-800 rounded-lg transition-colors"
                      title="View recurring transaction template"
                    >
                      <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                      </svg>
                      View Template: {{ recurringTemplate.description }}
                    </Link>
                  </div>
                </div>
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

              <!-- File Attachments Section -->
              <div class="border-t border-gray-200 pt-6 mt-6">
                <h3 class="text-lg font-medium text-gray-900 mb-4">File Attachments</h3>
                <FileUpload
                  :upload-url="`/transactions/${transaction.id}/files`"
                  @uploaded="handleFileUploaded"
                  @error="handleFileError"
                />
                <div v-if="attachments.length > 0" class="mt-6">
                  <FileAttachmentList
                    :attachments="attachments"
                    @deleted="handleFileDeleted"
                  />
                </div>
              </div>

              <!-- Activity Log Section -->
              <div class="border-t border-gray-200 pt-6 mt-6">
                <ActivityLog :activities="activities" />
                <div v-if="loadingActivities" class="flex justify-center py-4">
                  <svg class="animate-spin h-6 w-6 text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
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

              <!-- Action Buttons -->
              <div class="flex items-center justify-between mt-6">
                <div>
                  <button
                    type="button"
                    @click="confirmDelete"
                    class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-500 active:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition ease-in-out duration-150"
                  >
                    Delete Transaction
                  </button>
                </div>

                <div class="flex justify-end space-x-3">
                  <Link
                    :href="route('budget.transaction.index', budget.id)"
                    class="inline-flex items-center px-4 py-2 bg-gray-300 border border-transparent rounded-md font-semibold text-xs text-gray-800 uppercase tracking-widest hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition ease-in-out duration-150"
                  >
                    Cancel
                  </Link>

                  <PrimaryButton type="submit" :disabled="form.processing">
                    Update Transaction
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
import { Head, Link, useForm, usePage, router } from '@inertiajs/vue3';
import { ref, watch, onMounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import TextArea from '@/Components/TextArea.vue';
import SelectInput from '@/Components/SelectInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import FileUpload from '@/Components/FileUpload.vue';
import FileAttachmentList from '@/Components/FileAttachmentList.vue';
import ActivityLog from '@/Components/ActivityLog.vue';
import { formatCurrency } from '@/utils/format.js';

// Props
const props = defineProps({
  budget: Object,
  transaction: Object,
  accounts: Array,
  recurringTemplates: Array,
  edit: Boolean,
  patterns: Object,
});

const attachments = ref([]);
const fileError = ref('');
const activities = ref([]);
const loadingActivities = ref(false);

onMounted(() => {
  loadAttachments();
  loadActivities();
});

const loadAttachments = async () => {
  try {
    const response = await fetch(`/transactions/${props.transaction.id}/files`);
    if (response.ok) {
      const data = await response.json();
      attachments.value = data.attachments;
    }
  } catch (error) {
    console.error('Failed to load attachments:', error);
  }
};

const loadActivities = async () => {
  loadingActivities.value = true;
  try {
    const response = await fetch(`/budget/${props.budget.id}/transactions/${props.transaction.id}/activity-log`);
    if (response.ok) {
      const data = await response.json();
      activities.value = data.activities;
    }
  } catch (error) {
    console.error('Failed to load activity log:', error);
  } finally {
    loadingActivities.value = false;
  }
};

// Initialize form with transaction data
const form = useForm({
  description: props.transaction.description,
  amount: (props.transaction.amount_in_cents / 100).toString(),
  account_id: props.transaction.account_id,
  category: props.transaction.category,
  date: props.transaction.date,
  notes: props.transaction.notes || '',
  recurring_transaction_template_id: props.transaction.recurring_transaction_template_id || '',
});

// Form submission
const submit = () => {
  form.patch(route('budget.transaction.update', [props.budget.id, props.transaction.id]), {
    onSuccess: () => {
      // Reload activity log to show the update
      loadActivities();
    }
  });
};

// Delete confirmation
const confirmDelete = () => {
  if (confirm('Are you sure you want to delete this transaction?')) {
    router.delete(route('budget.transaction.destroy', [props.budget.id, props.transaction.id]));
  }
};

const handleFileUploaded = (attachment) => {
  attachments.value.push(attachment);
  fileError.value = '';
  // Refresh activity log to show file upload activity
  loadActivities();
};

const handleFileError = (error) => {
  fileError.value = error;
};

const handleFileDeleted = (attachmentId) => {
  attachments.value = attachments.value.filter(a => a.id !== attachmentId);
  // Refresh activity log to show file deletion activity
  loadActivities();
};
</script>
