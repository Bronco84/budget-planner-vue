<template>
  <Head :title="budget.name + ' - Find Matching Transactions'" />

  <AuthenticatedLayout>
    <div class="py-4" :class="{ 'pb-20': totalSelectedTransactions > 0 }">
      <div class="max-w-8xl mx-auto sm:px-2 lg:px-4">
        <div class="bg-white shadow-sm sm:rounded-lg">
          <div class="p-6">
            <!-- Header Row -->
            <div class="mb-6 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
              <div>
                <h2 class="text-xl font-semibold text-gray-900">Find Matching Transactions</h2>
                <p class="text-sm text-gray-600 mt-1">
                  Found <span class="font-semibold">{{ totalMatches }}</span> unlinked transactions 
                  matching <span class="font-semibold">{{ totalTemplates }}</span> recurring templates.
                </p>
              </div>
              <div class="flex items-center gap-2">
                <Link
                  :href="route('recurring-transactions.index', budget.id)"
                  class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 transition"
                >
                  Back to Templates
                </Link>
                <button
                  v-if="totalSelectedTransactions > 0"
                  @click="linkSelected"
                  :disabled="isLinking"
                  class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-500 transition disabled:opacity-50"
                >
                  <svg v-if="isLinking" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                  {{ isLinking ? 'Linking...' : `Link ${totalSelectedTransactions} Selected` }}
                </button>
              </div>
            </div>

            <!-- No Matches Message -->
            <div v-if="templatesWithMatches.length === 0" class="text-center py-10">
              <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
              <h3 class="mt-2 text-sm font-medium text-gray-900">No matching transactions found</h3>
              <p class="mt-1 text-sm text-gray-500">
                All unlinked transactions have already been reviewed or don't match any templates.
              </p>
            </div>

            <!-- Templates with Matches -->
            <div v-else class="space-y-6">
              <div 
                v-for="item in templatesWithMatches" 
                :key="item.template.id"
                class="border border-gray-200 rounded-lg overflow-hidden"
              >
                <!-- Template Header -->
                <div class="bg-gray-50 px-4 py-3 border-b border-gray-200">
                  <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                      <input
                        type="checkbox"
                        :checked="isTemplateFullySelected(item.template.id)"
                        :indeterminate="isTemplatePartiallySelected(item.template.id)"
                        @change="toggleTemplate(item.template.id, item.matching_transactions)"
                        class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                      />
                      <div>
                        <h4 class="text-base font-medium text-gray-900">{{ item.template.description }}</h4>
                        <div class="flex items-center flex-wrap gap-x-3 gap-y-1 text-sm text-gray-500">
                          <span>{{ formatCurrency(item.template.amount_in_cents) }}</span>
                          <span class="capitalize">{{ item.template.frequency }}</span>
                          <span>{{ item.template.account_name }}</span>
                          <span v-if="item.template.linked_count > 0" class="text-green-600">
                            {{ item.template.linked_count }} already linked
                          </span>
                        </div>
                      </div>
                    </div>
                    <div class="text-sm text-gray-500">
                      {{ item.matching_transactions.length }} match{{ item.matching_transactions.length !== 1 ? 'es' : '' }}
                    </div>
                  </div>
                </div>

                <!-- Matching Transactions -->
                <div class="divide-y divide-gray-100">
                  <div 
                    v-for="transaction in item.matching_transactions" 
                    :key="transaction.id"
                    class="px-4 py-3 flex items-center justify-between hover:bg-gray-50"
                  >
                    <div class="flex items-center space-x-3">
                      <input
                        type="checkbox"
                        :value="transaction.id"
                        v-model="selectedTransactions[item.template.id]"
                        class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                      />
                      <div>
                        <div class="text-sm font-medium text-gray-900">
                          {{ transaction.merchant_name || transaction.plaid_description || transaction.description }}
                        </div>
                        <div v-if="transaction.plaid_description && transaction.plaid_description !== transaction.merchant_name" class="text-xs text-gray-500">
                          {{ transaction.plaid_description }}
                        </div>
                      </div>
                    </div>
                    <div class="flex items-center space-x-4 text-sm">
                      <span :class="getAmountColor(transaction.amount_in_cents)">
                        {{ formatCurrency(transaction.amount_in_cents) }}
                      </span>
                      <span class="text-gray-500 w-24 text-right">{{ formatDate(transaction.date) }}</span>
                      <span class="text-gray-400 w-28 text-right truncate hidden sm:block">{{ transaction.account_name }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>

          </div>
        </div>
      </div>
    </div>

    <!-- Bottom Action Bar (sticky) -->
    <div v-if="totalSelectedTransactions > 0" class="fixed bottom-0 left-0 right-0 bg-white border-t border-gray-200 shadow-lg p-4 z-50">
      <div class="max-w-8xl mx-auto px-2 sm:px-2 lg:px-4 flex items-center justify-between">
        <div class="text-sm text-gray-600">
          <span class="font-semibold">{{ totalSelectedTransactions }}</span> transaction{{ totalSelectedTransactions !== 1 ? 's' : '' }} selected
        </div>
        <div class="flex items-center space-x-3">
          <button
            @click="clearSelection"
            class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
          >
            Clear Selection
          </button>
          <button
            @click="linkSelected"
            :disabled="isLinking"
            class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-500 focus:bg-blue-500 active:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition ease-in-out duration-150 disabled:opacity-50"
          >
            <svg v-if="isLinking" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            {{ isLinking ? 'Linking...' : `Link ${totalSelectedTransactions} Transaction${totalSelectedTransactions !== 1 ? 's' : ''}` }}
          </button>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { ref, computed, reactive } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatCurrency } from '@/utils/format.js';

const props = defineProps({
  budget: Object,
  templatesWithMatches: Array,
  totalTemplates: Number,
  totalMatches: Number,
});

// Track selected transactions per template
const selectedTransactions = reactive({});

// Initialize selection arrays for each template
props.templatesWithMatches.forEach(item => {
  selectedTransactions[item.template.id] = [];
});

const isLinking = ref(false);

// Computed: total selected transactions across all templates
const totalSelectedTransactions = computed(() => {
  return Object.values(selectedTransactions).reduce((sum, ids) => sum + ids.length, 0);
});

// Check if all transactions for a template are selected
const isTemplateFullySelected = (templateId) => {
  const item = props.templatesWithMatches.find(i => i.template.id === templateId);
  if (!item) return false;
  return selectedTransactions[templateId]?.length === item.matching_transactions.length;
};

// Check if some (but not all) transactions for a template are selected
const isTemplatePartiallySelected = (templateId) => {
  const item = props.templatesWithMatches.find(i => i.template.id === templateId);
  if (!item) return false;
  const selectedCount = selectedTransactions[templateId]?.length || 0;
  return selectedCount > 0 && selectedCount < item.matching_transactions.length;
};

// Toggle all transactions for a template
const toggleTemplate = (templateId, transactions) => {
  if (isTemplateFullySelected(templateId)) {
    selectedTransactions[templateId] = [];
  } else {
    selectedTransactions[templateId] = transactions.map(t => t.id);
  }
};

// Clear all selections
const clearSelection = () => {
  Object.keys(selectedTransactions).forEach(templateId => {
    selectedTransactions[templateId] = [];
  });
};

// Link selected transactions
const linkSelected = () => {
  const selections = [];
  
  Object.entries(selectedTransactions).forEach(([templateId, transactionIds]) => {
    if (transactionIds.length > 0) {
      selections.push({
        template_id: parseInt(templateId),
        transaction_ids: transactionIds,
      });
    }
  });
  
  if (selections.length === 0) return;
  
  isLinking.value = true;
  
  router.post(
    route('recurring-transactions.link-transactions', props.budget.id),
    { selections },
    {
      onFinish: () => {
        isLinking.value = false;
      },
    }
  );
};

// Utility methods
const formatDate = (dateString) => {
  if (!dateString) return 'N/A';
  const date = new Date(dateString);
  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
};

const getAmountColor = (amountCents) => {
  return amountCents >= 0 ? 'text-green-600 font-medium' : 'text-red-600 font-medium';
};
</script>
