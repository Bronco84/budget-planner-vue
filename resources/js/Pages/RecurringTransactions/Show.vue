<template>
  <Head :title="`${recurringTransaction.description} - Recurring Transaction`" />

  <AuthenticatedLayout>
    <template #header>
      <div class="flex justify-between items-center">
        <div>
          <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ recurringTransaction.description }}
          </h2>
          <p class="text-sm text-gray-600 mt-1">
            {{ recurringTransaction.frequency }} • {{ formatCurrency(recurringTransaction.amount_in_cents) }}
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

const activeRulesCount = computed(() => {
  return props.rules.filter(r => r.is_active).length;
});

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