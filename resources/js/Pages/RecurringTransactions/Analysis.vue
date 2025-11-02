<template>
  <Head :title="budget.name + ' - Recurring Transaction Analysis'" />

  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ budget.name }} - Recurring Transaction Analysis</h2>
        <div class="flex items-center space-x-3">
          <Link
            :href="route('budgets.show', budget.id)"
            class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm transition duration-150 ease-in-out hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25"
          >
            Back to Budget
          </Link>
          <Link
            :href="route('recurring-transactions.index', budget.id)"
            class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm transition duration-150 ease-in-out hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-25"
          >
            View Templates
          </Link>
        </div>
      </div>
    </template>

    <div class="py-4">
      <div class="max-w-8xl mx-auto sm:px-2 lg:px-4">
        <!-- Analysis Form -->
        <div class="bg-white shadow-sm sm:rounded-lg mb-6">
          <div class="p-6">
            <div class="mb-6">
              <h3 class="text-lg font-semibold text-gray-900 mb-4">Analyze Account for Recurring Patterns</h3>
              <p class="text-sm text-gray-600 mb-4">
                Select an account to analyze its transactions for recurring patterns. The system will identify potential recurring transactions based on description, amount, and timing patterns.
              </p>
            </div>

            <form @submit.prevent="runAnalysis" class="space-y-6">
              <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- Account Selection -->
                <div>
                  <label for="account_id" class="block text-sm font-medium text-gray-700">Account</label>
                  <select
                    id="account_id"
                    v-model="form.account_id"
                    required
                    class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                  >
                    <option value="">Select Account</option>
                    <option v-for="account in accounts" :key="account.id" :value="account.id">
                      {{ account.name }}
                    </option>
                  </select>
                  <div v-if="form.errors.account_id" class="mt-1 text-sm text-red-600">{{ form.errors.account_id }}</div>
                </div>

                <!-- Analysis Period -->
                <div>
                  <label for="analysis_period_months" class="block text-sm font-medium text-gray-700">Analysis Period (Months)</label>
                  <select
                    id="analysis_period_months"
                    v-model="form.analysis_period_months"
                    class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                  >
                    <option value="3">3 Months</option>
                    <option value="6">6 Months</option>
                    <option value="12">12 Months</option>
                    <option value="24">24 Months</option>
                  </select>
                  <div v-if="form.errors.analysis_period_months" class="mt-1 text-sm text-red-600">{{ form.errors.analysis_period_months }}</div>
                </div>

                <!-- Minimum Occurrences -->
                <div>
                  <label for="min_occurrences" class="block text-sm font-medium text-gray-700">Min Occurrences</label>
                  <select
                    id="min_occurrences"
                    v-model="form.min_occurrences"
                    class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                  >
                    <option value="2">2</option>
                    <option value="3">3</option>
                    <option value="4">4</option>
                    <option value="5">5</option>
                  </select>
                  <div v-if="form.errors.min_occurrences" class="mt-1 text-sm text-red-600">{{ form.errors.min_occurrences }}</div>
                </div>

                <!-- Confidence Threshold -->
                <div>
                  <label for="confidence_threshold" class="block text-sm font-medium text-gray-700">Confidence Threshold</label>
                  <select
                    id="confidence_threshold"
                    v-model="form.confidence_threshold"
                    class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                  >
                    <option value="0.5">50% - Low</option>
                    <option value="0.6">60% - Medium</option>
                    <option value="0.7">70% - High</option>
                    <option value="0.8">80% - Very High</option>
                  </select>
                  <div v-if="form.errors.confidence_threshold" class="mt-1 text-sm text-red-600">{{ form.errors.confidence_threshold }}</div>
                </div>
              </div>

              <div class="flex justify-end">
                <button
                  type="submit"
                  :disabled="form.processing || !form.account_id"
                  class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 focus:bg-indigo-500 active:bg-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150 disabled:opacity-50"
                >
                  <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                  {{ form.processing ? 'Analyzing...' : 'Run Analysis' }}
                </button>
              </div>
            </form>
          </div>
        </div>

        <!-- Analysis Results -->
        <div v-if="analysisResults" class="bg-white shadow-sm sm:rounded-lg">
          <div class="p-6">
            <div class="mb-6">
              <h3 class="text-lg font-semibold text-gray-900 mb-2">Analysis Results</h3>
              <div class="flex items-center space-x-4 text-sm text-gray-600">
                <div class="flex items-center">
                  <svg class="w-4 h-4 mr-1 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                  </svg>
                  Account: {{ selectedAccountName }}
                </div>
                <div class="flex items-center">
                  <svg class="w-4 h-4 mr-1 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                  </svg>
                  {{ analysisResults.patterns ? analysisResults.patterns.length : 0 }} patterns found
                </div>
              </div>

              <!-- Analysis Summary -->
              <div v-if="analysisResults.analysis_summary" class="mt-4 p-4 bg-gray-50 rounded-lg">
                <h4 class="text-sm font-medium text-gray-900 mb-2">Analysis Summary</h4>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                  <div>
                    <span class="text-gray-600">Transactions analyzed:</span>
                    <span class="font-medium ml-1">{{ analysisResults.analysis_summary.total_transactions || 'N/A' }}</span>
                  </div>
                  <div>
                    <span class="text-gray-600">Date range:</span>
                    <span class="font-medium ml-1">{{ formatDateRange(analysisResults.analysis_summary) }}</span>
                  </div>
                  <div>
                    <span class="text-gray-600">Pattern groups:</span>
                    <span class="font-medium ml-1">{{ analysisResults.analysis_summary.pattern_groups || 'N/A' }}</span>
                  </div>
                  <div>
                    <span class="text-gray-600">Avg confidence:</span>
                    <span class="font-medium ml-1">{{ formatConfidence(analysisResults.analysis_summary.average_confidence) }}</span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Results Table -->
            <div v-if="analysisResults.patterns && analysisResults.patterns.length > 0">
              <div class="flex justify-between items-center mb-4">
                <h4 class="text-md font-medium text-gray-900">Detected Patterns</h4>
                <button
                  @click="createSelectedTemplates"
                  :disabled="selectedPatterns.length === 0"
                  class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-500 focus:bg-blue-500 active:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition ease-in-out duration-150 disabled:opacity-50"
                >
                  Review & Create {{ selectedPatterns.length }} Template{{ selectedPatterns.length !== 1 ? 's' : '' }}
                </button>
              </div>

              <div class="overflow-x-auto border rounded-lg">
                <table class="min-w-full divide-y divide-gray-200">
                  <thead class="bg-gray-50">
                    <tr>
                      <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        <input
                          type="checkbox"
                          :checked="allPatternsSelected"
                          @change="toggleAllPatterns"
                          class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                        />
                      </th>
                      <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Description
                      </th>
                      <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Amount
                      </th>
                      <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Frequency
                      </th>
                      <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Occurrences
                      </th>
                      <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Confidence
                      </th>
                      <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Last Seen
                      </th>
                    </tr>
                  </thead>
                  <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="pattern in analysisResults.patterns" :key="pattern.id || pattern.description">
                      <td class="px-6 py-4 whitespace-nowrap">
                        <input
                          type="checkbox"
                          :value="pattern"
                          v-model="selectedPatterns"
                          class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                        />
                      </td>
                      <td class="px-6 py-4">
                        <div class="text-sm font-medium text-gray-900">
                          {{ getDisplayDescription(pattern) }}
                        </div>
                        <div v-if="pattern.original_description && pattern.original_description !== pattern.description" class="text-xs text-gray-500 mt-1">
                          Original: {{ pattern.original_description }}
                        </div>
                        <div v-if="pattern.sample_transactions && pattern.sample_transactions.length > 0" class="text-xs text-gray-500 mt-1">
                          Sample dates: {{ formatSampleDates(pattern.sample_transactions) }}
                        </div>
                      </td>
                      <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm" :class="getAmountColor(pattern.amount_in_cents)">
                          {{ formatCurrency(pattern.amount_in_cents) }}
                        </div>
                        <div v-if="pattern.amount_variance > 0" class="text-xs text-gray-500">
                          ±{{ formatCurrency(pattern.amount_variance) }}
                        </div>
                      </td>
                      <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-900 capitalize">{{ pattern.frequency }}</div>
                        <div v-if="pattern.interval_days" class="text-xs text-gray-500">
                          ~{{ pattern.interval_days }} days
                        </div>
                      </td>
                      <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-900">{{ pattern.occurrence_count }}</div>
                      </td>
                      <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                          <div class="text-sm text-gray-900">{{ formatConfidence(pattern.confidence_score) }}</div>
                          <div class="ml-2 w-16 bg-gray-200 rounded-full h-2">
                            <div
                              class="h-2 rounded-full"
                              :class="getConfidenceColor(pattern.confidence_score)"
                              :style="{ width: (pattern.confidence_score * 100) + '%' }"
                            ></div>
                          </div>
                        </div>
                      </td>
                      <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm text-gray-900">{{ formatDate(pattern.last_transaction_date) }}</div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- No Results Message -->
            <div v-else-if="analysisResults && (!analysisResults.patterns || analysisResults.patterns.length === 0)" class="text-center py-10">
              <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
              </svg>
              <h3 class="mt-2 text-sm font-medium text-gray-900">No recurring patterns found</h3>
              <p class="mt-1 text-sm text-gray-500">
                Try adjusting the analysis parameters or select an account with more transaction history.
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Confirmation Modal -->
    <RecurringTransactionConfirmationModal
      :show="showConfirmationModal"
      :patterns="selectedPatterns"
      :account="analysisResults?.account || null"
      @close="closeConfirmationModal"
      @create="handleTemplateCreation"
    />
  </AuthenticatedLayout>
</template>

<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import RecurringTransactionConfirmationModal from './Partials/RecurringTransactionConfirmationModal.vue';
import { formatCurrency } from '@/utils/format.js';

const props = defineProps({
  budget: Object,
  accounts: Array,
  analysisResult: Object,
});

// Form for analysis parameters
const form = useForm({
  account_id: '',
  analysis_period_months: 6,
  min_occurrences: 3,
  confidence_threshold: 0.6,
});

// Analysis results and state
const analysisResults = ref(props.analysisResult || null);
const selectedPatterns = ref([]);
const creatingTemplates = ref(false);
const showConfirmationModal = ref(false);

// Computed properties
const selectedAccountName = computed(() => {
  if (!form.account_id || !props.accounts) return '';
  const account = props.accounts.find(a => a.id === parseInt(form.account_id));
  return account ? account.name : '';
});

const selectedAccount = computed(() => {
  if (!form.account_id || !props.accounts) return null;
  return props.accounts.find(a => a.id === parseInt(form.account_id));
});

const allPatternsSelected = computed(() => {
  if (!analysisResults.value?.patterns || analysisResults.value.patterns.length === 0) return false;
  return selectedPatterns.value.length === analysisResults.value.patterns.length;
});

// Methods
const runAnalysis = () => {
  form.post(route('recurring-transactions.analysis.analyze', props.budget.id), {
    onSuccess: (page) => {
      console.log('Analysis success response:', page);
      console.log('Analysis result prop:', page.props.analysisResult);
      if (page.props.analysisResult) {
        analysisResults.value = page.props.analysisResult;
        selectedPatterns.value = [];
      }
    },
    onError: (errors) => {
      console.error('Analysis failed:', errors);
    },
    preserveState: true,
  });
};

const toggleAllPatterns = (event) => {
  if (event.target.checked) {
    selectedPatterns.value = [...analysisResults.value.patterns];
  } else {
    selectedPatterns.value = [];
  }
};

const createSelectedTemplates = () => {
  if (selectedPatterns.value.length === 0) return;
  showConfirmationModal.value = true;
};

const handleTemplateCreation = async (templatesData) => {
  creatingTemplates.value = true;

  // Use Inertia form for better response handling
  const templateForm = useForm({
    account_id: form.account_id,
    selected_patterns: templatesData,
  });

  templateForm.post(route('recurring-transactions.analysis.create-templates', props.budget.id), {
    onSuccess: (page) => {
      console.log('Template creation success:', page);
      creatingTemplates.value = false;
      selectedPatterns.value = [];
      showConfirmationModal.value = false;
    },
    onError: (errors) => {
      console.error('Template creation failed:', errors);
      creatingTemplates.value = false;
    },
    onFinish: () => {
      creatingTemplates.value = false;
    }
  });
};

const closeConfirmationModal = () => {
  showConfirmationModal.value = false;
};

// Utility methods
const formatDate = (dateString) => {
  if (!dateString) return 'N/A';
  const date = new Date(dateString);
  return date.toLocaleDateString();
};

const formatDateRange = (summary) => {
  if (!summary?.analysis_period?.start_date || !summary?.analysis_period?.end_date) return 'N/A';
  const start = new Date(summary.analysis_period.start_date).toLocaleDateString();
  const end = new Date(summary.analysis_period.end_date).toLocaleDateString();
  return `${start} - ${end}`;
};

const formatConfidence = (score) => {
  if (score === null || score === undefined) return 'N/A';
  return Math.round(score * 100) + '%';
};

const formatSampleDates = (transactions) => {
  if (!transactions || transactions.length === 0) return '';
  return transactions
    .slice(0, 3)
    .map(t => new Date(t.date).toLocaleDateString())
    .join(', ');
};

const getAmountColor = (amountCents) => {
  return amountCents >= 0 ? 'text-green-600' : 'text-red-600';
};

const getConfidenceColor = (score) => {
  if (score >= 0.8) return 'bg-green-500';
  if (score >= 0.6) return 'bg-yellow-500';
  return 'bg-red-500';
};

const getDisplayDescription = (pattern) => {
  // Return the normalized description if it exists and is not empty
  if (pattern.description && pattern.description.trim() !== '') {
    return pattern.description;
  }

  // Fall back to original description
  if (pattern.original_description && pattern.original_description.trim() !== '') {
    return pattern.original_description;
  }

  // Final fallback for patterns without descriptions
  return 'Transaction Pattern (No Description)';
};
</script>