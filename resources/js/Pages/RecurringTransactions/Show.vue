<template>
  <Head :title="`${recurringTransaction.friendly_label || recurringTransaction.description} - Recurring Transaction`" />

  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <div>
          <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ recurringTransaction.friendly_label || recurringTransaction.description }}
          </h2>
          <p class="text-sm text-gray-600 mt-1">
            {{ recurringTransaction.frequency }} • {{ formatCurrency(recurringTransaction.amount_in_cents) }}
          </p>
          <p v-if="recurringTransaction.friendly_label" class="text-xs text-gray-500 mt-1">
            Matches: {{ recurringTransaction.description }}
          </p>
        </div>
        <Link
          :href="route('recurring-transactions.index', budget.id)"
          class="inline-flex items-center px-4 py-2 bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-300 active:bg-gray-400 focus:outline-none focus:border-gray-900 focus:ring focus:ring-gray-300 disabled:opacity-25 transition"
        >
          <ArrowLeftIcon class="w-4 h-4 mr-2" />
          Back to List
        </Link>
      </div>
    </template>

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Summary Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
          <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-4">
            <div class="text-sm text-gray-600">Linked Transactions</div>
            <div class="text-2xl font-bold text-gray-900">{{ linkedTransactions.length }}</div>
          </div>
          <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-4">
            <div class="text-sm text-gray-600">Active Rules</div>
            <div class="text-2xl font-bold text-gray-900">{{ activeRulesCount }}</div>
          </div>
          <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-4">
            <div class="text-sm text-gray-600">Next Occurrence</div>
            <div class="text-lg font-semibold text-gray-900">{{ formatDate(getNextOccurrence()) }}</div>
          </div>
          <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-4">
            <div class="text-sm text-gray-600">Account</div>
            <div class="text-sm font-semibold text-gray-900">{{ recurringTransaction.account?.name || 'N/A' }}</div>
          </div>
        </div>

        <!-- Main Content with Tabs -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <!-- Tab Navigation -->
          <div class="border-b border-gray-200">
            <nav class="-mb-px flex space-x-8 px-6" aria-label="Tabs">
              <button
                @click="activeTab = 'overview'"
                :class="[
                  activeTab === 'overview'
                    ? 'border-indigo-500 text-indigo-600'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
                  'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm'
                ]"
              >
                Overview
              </button>
              <button
                @click="activeTab = 'rules'"
                :class="[
                  activeTab === 'rules'
                    ? 'border-indigo-500 text-indigo-600'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
                  'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm'
                ]"
              >
                Rules
                <span v-if="rules.length > 0" class="ml-2 py-0.5 px-2 rounded-full text-xs font-medium" :class="activeTab === 'rules' ? 'bg-indigo-100 text-indigo-600' : 'bg-gray-100 text-gray-600'">
                  {{ rules.length }}
                </span>
              </button>
              <button
                @click="activeTab = 'linked'"
                :class="[
                  activeTab === 'linked'
                    ? 'border-indigo-500 text-indigo-600'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
                  'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm'
                ]"
              >
                Linked Transactions
                <span v-if="linkedTransactions.length > 0" class="ml-2 py-0.5 px-2 rounded-full text-xs font-medium" :class="activeTab === 'linked' ? 'bg-indigo-100 text-indigo-600' : 'bg-gray-100 text-gray-600'">
                  {{ linkedTransactions.length }}
                </span>
              </button>
              <button
                @click="loadDiagnostics(); activeTab = 'diagnostics'"
                :class="[
                  activeTab === 'diagnostics'
                    ? 'border-indigo-500 text-indigo-600'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
                  'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm'
                ]"
              >
                Matching Diagnostics
              </button>
            </nav>
          </div>

          <!-- Tab Content -->
          <div class="p-6">
            <!-- Overview Tab -->
            <div v-show="activeTab === 'overview'">
              <RecurringTransactionOverview
                :budget="budget"
                :recurring-transaction="recurringTransaction"
                :accounts="accounts"
                :linked-transactions="linkedTransactions"
                :rules="rules"
                :eligible-credit-cards="eligibleCreditCards"
              />
            </div>

            <!-- Rules Tab -->
            <div v-show="activeTab === 'rules'">
              <RecurringTransactionRules
                :budget="budget"
                :recurring-transaction="recurringTransaction"
                :rules="rules"
                :field-options="fieldOptions"
                :operator-options="operatorOptions"
              />
            </div>

            <!-- Linked Transactions Tab -->
            <div v-show="activeTab === 'linked'">
              <RecurringTransactionLinked
                :budget="budget"
                :recurring-transaction="recurringTransaction"
                :linked-transactions="linkedTransactions"
              />
            </div>
            
            <!-- Diagnostics Tab -->
            <div v-show="activeTab === 'diagnostics'">
              <div v-if="loadingDiagnostics" class="flex justify-center py-8">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
              </div>
              
              <div v-else-if="diagnostics" class="space-y-6">
                <!-- Matching Method -->
                <div class="bg-gray-50 rounded-lg p-4">
                  <h4 class="text-sm font-semibold text-gray-700 mb-2">Matching Method</h4>
                  <div class="flex items-center space-x-2">
                    <span 
                      class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium"
                      :class="{
                        'bg-green-100 text-green-800': diagnostics.matching_method === 'plaid_entity_id',
                        'bg-blue-100 text-blue-800': diagnostics.matching_method === 'rules',
                        'bg-yellow-100 text-yellow-800': diagnostics.matching_method === 'description'
                      }"
                    >
                      {{ matchingMethodLabel }}
                    </span>
                    <span v-if="diagnostics.plaid_entity_name" class="text-sm text-gray-600">
                      ({{ diagnostics.plaid_entity_name }})
                    </span>
                  </div>
                  <p class="mt-2 text-xs text-gray-500">
                    <template v-if="diagnostics.matching_method === 'plaid_entity_id'">
                      Using Plaid's stable entity ID for reliable transaction matching.
                    </template>
                    <template v-else-if="diagnostics.matching_method === 'rules'">
                      Matching transactions using defined rules.
                    </template>
                    <template v-else>
                      Matching by description text similarity.
                    </template>
                  </p>
                </div>
                
                <!-- Plaid Entity Info (if available) -->
                <div v-if="diagnostics.plaid_entity_id" class="bg-green-50 border border-green-200 rounded-lg p-4">
                  <h4 class="text-sm font-semibold text-green-800 mb-2">Plaid Entity Link</h4>
                  <div class="grid grid-cols-2 gap-2 text-sm">
                    <div>
                      <span class="text-gray-600">Entity Name:</span>
                      <span class="ml-2 font-medium">{{ diagnostics.plaid_entity_name }}</span>
                    </div>
                    <div>
                      <span class="text-gray-600">Entity ID:</span>
                      <code class="ml-2 text-xs bg-gray-100 px-1 py-0.5 rounded">{{ diagnostics.plaid_entity_id }}</code>
                    </div>
                  </div>
                </div>
                
                <!-- Linked Credit Card (if available) -->
                <div v-if="diagnostics.linked_credit_card" class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                  <h4 class="text-sm font-semibold text-blue-800 mb-2">Linked Credit Card (Autopay Override)</h4>
                  <div class="grid grid-cols-2 gap-2 text-sm">
                    <div>
                      <span class="text-gray-600">Card:</span>
                      <span class="ml-2 font-medium">{{ diagnostics.linked_credit_card.name }}</span>
                    </div>
                    <div>
                      <span class="text-gray-600">Autopay:</span>
                      <span class="ml-2" :class="diagnostics.linked_credit_card.autopay_enabled ? 'text-green-600' : 'text-gray-400'">
                        {{ diagnostics.linked_credit_card.autopay_enabled ? 'Enabled' : 'Disabled' }}
                      </span>
                    </div>
                    <div>
                      <span class="text-gray-600">Current Balance:</span>
                      <span class="ml-2 font-medium">{{ formatCurrency(diagnostics.linked_credit_card.current_balance) }}</span>
                    </div>
                    <div>
                      <span class="text-gray-600">Statement Balance:</span>
                      <span class="ml-2 font-medium">{{ formatCurrency(diagnostics.linked_credit_card.statement_balance) }}</span>
                    </div>
                  </div>
                </div>
                
                <!-- Rules Summary -->
                <div v-if="diagnostics.rules_summary?.length > 0">
                  <h4 class="text-sm font-semibold text-gray-700 mb-2">Active Rules</h4>
                  <div class="overflow-hidden rounded-lg border border-gray-200">
                    <table class="min-w-full divide-y divide-gray-200">
                      <thead class="bg-gray-50">
                        <tr>
                          <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Field</th>
                          <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Operator</th>
                          <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Value</th>
                          <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        </tr>
                      </thead>
                      <tbody class="divide-y divide-gray-200 bg-white">
                        <tr v-for="rule in diagnostics.rules_summary" :key="rule.id">
                          <td class="px-4 py-2 text-sm text-gray-900">{{ rule.field }}</td>
                          <td class="px-4 py-2 text-sm text-gray-600">{{ rule.operator }}</td>
                          <td class="px-4 py-2 text-sm font-mono text-gray-900">{{ rule.value }}</td>
                          <td class="px-4 py-2">
                            <span :class="rule.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'" class="px-2 py-0.5 rounded-full text-xs">
                              {{ rule.is_active ? 'Active' : 'Inactive' }}
                            </span>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
                
                <!-- Potential Matches -->
                <div v-if="diagnostics.potential_matches?.length > 0">
                  <h4 class="text-sm font-semibold text-gray-700 mb-2">
                    Potential Matches 
                    <span class="text-gray-400 font-normal">({{ diagnostics.potential_matches.length }} found)</span>
                  </h4>
                  <div class="overflow-hidden rounded-lg border border-gray-200 max-h-96 overflow-y-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                      <thead class="bg-gray-50 sticky top-0">
                        <tr>
                          <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                          <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                          <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                          <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        </tr>
                      </thead>
                      <tbody class="divide-y divide-gray-200 bg-white">
                        <tr 
                          v-for="tx in diagnostics.potential_matches" 
                          :key="tx.id"
                          :class="tx.is_linked_here ? 'bg-green-50' : (tx.linked_to_template_id ? 'bg-yellow-50' : '')"
                        >
                          <td class="px-4 py-2 text-sm text-gray-600">{{ tx.date }}</td>
                          <td class="px-4 py-2 text-sm text-gray-900 truncate max-w-xs">{{ tx.description }}</td>
                          <td class="px-4 py-2 text-sm text-right font-mono" :class="tx.amount < 0 ? 'text-red-600' : 'text-green-600'">
                            {{ formatCurrency(tx.amount * 100) }}
                          </td>
                          <td class="px-4 py-2">
                            <span v-if="tx.is_linked_here" class="text-green-600 text-xs font-medium">✓ Linked here</span>
                            <span v-else-if="tx.linked_to_template_id" class="text-yellow-600 text-xs">Linked elsewhere</span>
                            <span v-else class="text-gray-400 text-xs">Unlinked</span>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
                
                <!-- Currently Linked Transactions -->
                <div v-if="diagnostics.linked_transactions?.length > 0">
                  <h4 class="text-sm font-semibold text-gray-700 mb-2">
                    Currently Linked 
                    <span class="text-gray-400 font-normal">({{ diagnostics.linked_transactions.length }})</span>
                  </h4>
                  <div class="overflow-hidden rounded-lg border border-gray-200 max-h-64 overflow-y-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                      <thead class="bg-gray-50 sticky top-0">
                        <tr>
                          <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                          <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                          <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                          <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Has Plaid</th>
                        </tr>
                      </thead>
                      <tbody class="divide-y divide-gray-200 bg-white">
                        <tr v-for="tx in diagnostics.linked_transactions" :key="tx.id">
                          <td class="px-4 py-2 text-sm text-gray-600">{{ tx.date }}</td>
                          <td class="px-4 py-2 text-sm text-gray-900 truncate max-w-xs">{{ tx.description }}</td>
                          <td class="px-4 py-2 text-sm text-right font-mono" :class="tx.amount < 0 ? 'text-red-600' : 'text-green-600'">
                            {{ formatCurrency(tx.amount * 100) }}
                          </td>
                          <td class="px-4 py-2">
                            <span v-if="tx.has_plaid" class="text-green-500 text-xs">✓ Yes</span>
                            <span v-else class="text-gray-400 text-xs">No</span>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
              
              <div v-else class="text-center py-8 text-gray-500">
                Failed to load diagnostics data.
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeftIcon } from '@heroicons/vue/24/solid';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import RecurringTransactionOverview from './Partials/RecurringTransactionOverview.vue';
import RecurringTransactionRules from './Partials/RecurringTransactionRules.vue';
import RecurringTransactionLinked from './Partials/RecurringTransactionLinked.vue';
import { formatCurrency } from '@/utils/format.js';
import axios from 'axios';

const props = defineProps({
  budget: Object,
  recurringTransaction: Object,
  accounts: Array,
  rules: Array,
  linkedTransactions: Array,
  fieldOptions: Object,
  operatorOptions: Object,
  eligibleCreditCards: Array,
});

const activeTab = ref('overview');
const diagnostics = ref(null);
const loadingDiagnostics = ref(false);
const diagnosticsLoaded = ref(false);

const activeRulesCount = computed(() => {
  return props.rules.filter(r => r.is_active).length;
});

const matchingMethodLabel = computed(() => {
  if (!diagnostics.value) return '';
  switch (diagnostics.value.matching_method) {
    case 'plaid_entity_id': return 'Plaid Entity ID';
    case 'rules': return 'Rules-Based';
    case 'description': return 'Description';
    default: return diagnostics.value.matching_method;
  }
});

const loadDiagnostics = async () => {
  if (diagnosticsLoaded.value) return;
  
  loadingDiagnostics.value = true;
  try {
    const response = await axios.get(route('recurring-transactions.diagnostics', {
      budget: props.budget.id,
      recurring_transaction: props.recurringTransaction.id
    }));
    diagnostics.value = response.data;
    diagnosticsLoaded.value = true;
  } catch (error) {
    console.error('Failed to load diagnostics:', error);
  } finally {
    loadingDiagnostics.value = false;
  }
};

const formatDate = (dateString) => {
  if (!dateString) return 'N/A';
  const date = new Date(dateString);
  return date.toLocaleDateString();
};

const getNextOccurrence = () => {
  if (!props.recurringTransaction.start_date) return null;

  const today = new Date();
  let nextDate = new Date(props.recurringTransaction.start_date);

  if (props.recurringTransaction.end_date && new Date(props.recurringTransaction.end_date) < today) {
    return null;
  }

  if (nextDate > today) {
    return nextDate;
  }

  // Calculate based on frequency (simplified version)
  switch (props.recurringTransaction.frequency) {
    case 'daily':
      nextDate = new Date();
      nextDate.setDate(today.getDate() + 1);
      break;
    case 'weekly':
      nextDate = new Date();
      nextDate.setDate(today.getDate() + 7);
      break;
    case 'monthly':
      nextDate = new Date();
      nextDate.setMonth(today.getMonth() + 1);
      break;
    default:
      nextDate = new Date();
      nextDate.setDate(today.getDate() + 30);
  }

  return nextDate;
};
</script>