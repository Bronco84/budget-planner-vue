<template>
  <div v-if="isEligible" class="autopay-configuration">
    <form @submit.prevent="submitAutopayConfig">
      <!-- Autopay Toggle -->
      <div class="flex items-center justify-between mb-4">
        <div>
          <label class="text-sm font-medium text-gray-700 dark:text-gray-300">
            Enable Autopay
          </label>
          <p class="text-xs text-gray-500 dark:text-gray-400">
            Automatically deduct statement balance from a linked account
          </p>
        </div>
        <input
          v-model="form.autopay_enabled"
          type="checkbox"
          class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
        />
      </div>

      <!-- Source Account Selection (shown when autopay enabled) -->
      <div v-if="form.autopay_enabled" class="space-y-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
            Payment Source Account
          </label>
          <select
            v-model="form.autopay_source_account_id"
            class="w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-gray-100"
            required
          >
            <option :value="null">Select an account...</option>
            <option
              v-for="account in eligibleSourceAccounts"
              :key="account.id"
              :value="account.id"
            >
              {{ account.name }} ({{ formatCurrency(account.current_balance_cents) }})
            </option>
          </select>
          <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            Choose the checking or savings account that will fund the autopay
          </p>
        </div>

        <!-- Optional: Amount Override -->
        <div>
          <label class="flex items-center text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
            <input
              v-model="useAmountOverride"
              type="checkbox"
              class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded mr-2"
            />
            Override payment amount
          </label>

          <div v-if="useAmountOverride" class="mt-2">
            <input
              v-model="amountOverrideDollars"
              type="number"
              step="0.01"
              min="0"
              placeholder="0.00"
              class="w-full border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-gray-100"
            />
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
              Leave unchecked to use statement balance ({{ formatCurrency(statementBalance) }})
            </p>
          </div>
        </div>

        <!-- Autopay Summary -->
        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-3">
          <p class="text-sm text-blue-800 dark:text-blue-200">
            <strong>Autopay Summary:</strong><br />
            On {{ formatDate(nextPaymentDate) }}, {{ paymentAmount }} will be deducted from {{ selectedSourceAccountName }}
          </p>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="flex gap-2 mt-4">
        <button
          type="submit"
          :disabled="form.processing"
          class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-md disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {{ form.processing ? 'Saving...' : 'Save Autopay Settings' }}
        </button>
        <button
          v-if="account.autopay_enabled"
          type="button"
          @click="disableAutopay"
          class="px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-md"
        >
          Disable
        </button>
      </div>

      <!-- Error Display -->
      <div v-if="form.errors && Object.keys(form.errors).length > 0" class="mt-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-3">
        <ul class="text-sm text-red-600 dark:text-red-400 list-disc list-inside">
          <li v-for="(error, field) in form.errors" :key="field">{{ error }}</li>
        </ul>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { formatCurrency } from '@/utils/format.js';

const props = defineProps({
  account: {
    type: Object,
    required: true,
  },
  budgetId: {
    type: Number,
    required: true,
  },
  eligibleSourceAccounts: {
    type: Array,
    default: () => [],
  },
});

// Check if account is eligible for autopay
const isEligible = computed(() => {
  return props.account?.plaid_account?.account_type === 'credit' &&
         props.account?.plaid_account?.account_subtype === 'credit card' &&
         props.account?.plaid_account?.last_statement_balance_cents !== null;
});

// Extract statement data
const statementBalance = computed(() => props.account?.plaid_account?.last_statement_balance_cents || 0);
const nextPaymentDate = computed(() => props.account?.plaid_account?.next_payment_due_date);

// Form state
const useAmountOverride = ref(!!props.account?.autopay_amount_override_cents);
const amountOverrideDollars = ref(
  props.account?.autopay_amount_override_cents
    ? (props.account.autopay_amount_override_cents / 100).toFixed(2)
    : ''
);

const form = useForm({
  autopay_enabled: props.account?.autopay_enabled || false,
  autopay_source_account_id: props.account?.autopay_source_account_id || null,
  autopay_amount_override_cents: props.account?.autopay_amount_override_cents || null,
});

// Watch amount override checkbox
watch(useAmountOverride, (newValue) => {
  if (!newValue) {
    amountOverrideDollars.value = '';
    form.autopay_amount_override_cents = null;
  }
});

// Watch amount override input
watch(amountOverrideDollars, (newValue) => {
  if (newValue && !isNaN(parseFloat(newValue))) {
    form.autopay_amount_override_cents = Math.round(parseFloat(newValue) * 100);
  } else {
    form.autopay_amount_override_cents = null;
  }
});

// Computed values for summary
const selectedSourceAccountName = computed(() => {
  const account = props.eligibleSourceAccounts.find(a => a.id === form.autopay_source_account_id);
  return account?.name || 'selected account';
});

const paymentAmount = computed(() => {
  const amount = form.autopay_amount_override_cents || statementBalance.value;
  return formatCurrency(amount);
});

// Submit handler
const submitAutopayConfig = () => {
  form.post(`/budgets/${props.budgetId}/accounts/${props.account.id}/autopay`, {
    preserveScroll: true,
    onSuccess: () => {
      // Form will be reset by Inertia
    },
  });
};

// Quick disable
const disableAutopay = () => {
  form.autopay_enabled = false;
  form.autopay_source_account_id = null;
  form.autopay_amount_override_cents = null;
  submitAutopayConfig();
};

// Format date
const formatDate = (dateString) => {
  if (!dateString) return 'N/A';
  const date = new Date(dateString);
  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
};
</script>

<style scoped>
.autopay-configuration {
  @apply bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700;
}
</style>
