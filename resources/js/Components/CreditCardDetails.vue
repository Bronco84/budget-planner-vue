<template>
  <div v-if="isCreditCard" class="credit-card-details">
    <!-- Credit Utilization Progress Bar -->
    <div v-if="hasCreditLimit" class="mb-3">
      <div class="flex justify-between items-center mb-1">
        <span class="text-xs font-medium text-gray-600 dark:text-gray-400">Credit Utilization</span>
        <span 
          class="text-xs font-bold"
          :class="utilizationColorClass"
        >
          {{ utilizationPercentage.toFixed(0) }}%
        </span>
      </div>
      
      <!-- Progress Bar -->
      <div class="relative h-2.5 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
        <div 
          class="absolute top-0 left-0 h-full rounded-full transition-all duration-300"
          :class="utilizationBarClass"
          :style="{ width: Math.min(utilizationPercentage, 100) + '%' }"
        ></div>
      </div>
      
      <!-- Labels -->
      <div class="flex justify-between items-center mt-1.5 text-[10px] text-gray-500 dark:text-gray-400">
        <span>{{ formatCurrency(currentBalance) }} used</span>
        <span>{{ formatCurrency(creditLimit) }} limit</span>
      </div>
      <div class="text-center mt-0.5">
        <span class="text-[10px] font-medium" :class="utilizationColorClass">
          {{ formatCurrency(availableCredit) }} available
        </span>
      </div>
    </div>

    <!-- Simplified View - Statement Balance & Minimum Payment -->
    <div class="space-y-2">
      <!-- Statement Balance -->
      <div class="flex justify-between items-center">
        <span class="text-xs text-gray-600 dark:text-gray-400">Statement Balance</span>
        <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">
          {{ formatCurrency(statementBalance) }}
        </span>
      </div>

      <!-- Minimum Payment -->
      <div v-if="minimumPayment" class="flex justify-between items-center">
        <span class="text-xs text-gray-600 dark:text-gray-400">Minimum Payment</span>
        <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">
          {{ formatCurrency(minimumPayment) }}
        </span>
      </div>

      <!-- Payment Due Date -->
      <div v-if="nextPaymentDueDate" class="flex justify-between items-center">
        <span class="text-xs text-gray-600 dark:text-gray-400">Payment Due</span>
        <div class="flex items-center gap-2">
          <span :class="paymentDueClass" class="text-sm font-semibold">
            {{ formatDate(nextPaymentDueDate) }}
          </span>
          <span v-if="daysUntilDue !== null" :class="urgencyBadgeClass" class="px-2 py-0.5 text-xs rounded-full font-medium">
            {{ daysUntilDue }}d
          </span>
        </div>
      </div>
    </div>

    <!-- Autopay Toggle - only show if account has statement data -->
    <div v-if="isAutopayEligible" class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700">
      <button
        @click="showAutopayConfig = !showAutopayConfig"
        class="w-full flex items-center justify-between text-sm text-blue-600 dark:text-blue-400 hover:underline"
      >
        <span>
          {{ account.autopay_enabled ? '✓ Autopay Enabled' : 'Configure Autopay' }}
        </span>
        <svg
          :class="{ 'rotate-180': showAutopayConfig }"
          class="w-4 h-4 transition-transform"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
        </svg>
      </button>

      <!-- Autopay Configuration Panel -->
      <div v-if="showAutopayConfig" class="mt-3">
        <AutopayConfiguration
          :account="account"
          :budget-id="budgetId"
          :eligible-source-accounts="eligibleSourceAccounts"
        />
      </div>
    </div>
    
    <!-- Message when autopay not available -->
    <div v-else class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700">
      <p class="text-xs text-gray-500 dark:text-gray-400 italic">
        Autopay unavailable – statement data not synced from your bank.
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { formatCurrency } from '@/utils/format.js';
import AutopayConfiguration from '@/Components/AutopayConfiguration.vue';

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

const showAutopayConfig = ref(false);

// Check if this is a credit card account
const isCreditCard = computed(() => {
  return props.account?.plaid_account?.account_type === 'credit' &&
         props.account?.plaid_account?.account_subtype === 'credit card';
});

// Extract credit card data from account
const plaidAccount = computed(() => props.account?.plaid_account || {});

const statementBalance = computed(() => plaidAccount.value.last_statement_balance_cents || 0);
const minimumPayment = computed(() => plaidAccount.value.minimum_payment_amount_cents);
const nextPaymentDueDate = computed(() => plaidAccount.value.next_payment_due_date);

// Credit utilization data
const currentBalance = computed(() => plaidAccount.value.current_balance_cents || 0);
const availableCredit = computed(() => plaidAccount.value.available_balance_cents || 0);

// Credit limit: use stored value, or calculate from current_balance + available_balance
const creditLimit = computed(() => {
  if (plaidAccount.value.credit_limit_cents) {
    return plaidAccount.value.credit_limit_cents;
  }
  // For credit cards: credit_limit = current_balance + available_credit
  if (currentBalance.value > 0 || availableCredit.value > 0) {
    return currentBalance.value + availableCredit.value;
  }
  return 0;
});

// Check if we have enough data to show credit utilization
const hasCreditLimit = computed(() => creditLimit.value > 0);

// Calculate utilization percentage
const utilizationPercentage = computed(() => {
  if (!hasCreditLimit.value || creditLimit.value === 0) return 0;
  return (currentBalance.value / creditLimit.value) * 100;
});

// Color classes based on utilization level
const utilizationColorClass = computed(() => {
  const pct = utilizationPercentage.value;
  if (pct < 30) return 'text-green-600 dark:text-green-400';
  if (pct < 50) return 'text-yellow-600 dark:text-yellow-400';
  if (pct < 70) return 'text-orange-600 dark:text-orange-400';
  return 'text-red-600 dark:text-red-400';
});

// Progress bar color class
const utilizationBarClass = computed(() => {
  const pct = utilizationPercentage.value;
  if (pct < 30) return 'bg-green-500';
  if (pct < 50) return 'bg-yellow-500';
  if (pct < 70) return 'bg-orange-500';
  return 'bg-red-500';
});

// Check if autopay is eligible (requires statement balance data)
const isAutopayEligible = computed(() => {
  return plaidAccount.value.last_statement_balance_cents !== null &&
         plaidAccount.value.last_statement_balance_cents !== undefined;
});

// Calculate days until payment is due
const daysUntilDue = computed(() => {
  if (!nextPaymentDueDate.value) return null;
  const dueDate = new Date(nextPaymentDueDate.value);
  const today = new Date();
  const diffTime = dueDate - today;
  const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
  return diffDays;
});

// Styling for payment due date based on urgency
const paymentDueClass = computed(() => {
  if (daysUntilDue.value === null) return 'text-gray-900 dark:text-gray-100';
  if (daysUntilDue.value < 0) return 'text-red-700 dark:text-red-300';
  if (daysUntilDue.value < 3) return 'text-red-600 dark:text-red-400';
  if (daysUntilDue.value < 7) return 'text-orange-600 dark:text-orange-400';
  return 'text-gray-900 dark:text-gray-100';
});

// Badge styling for days remaining
const urgencyBadgeClass = computed(() => {
  if (daysUntilDue.value === null) return '';
  if (daysUntilDue.value < 0) return 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
  if (daysUntilDue.value < 3) return 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
  if (daysUntilDue.value < 7) return 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200';
  return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
});

// Format date for display
const formatDate = (dateString) => {
  if (!dateString) return 'N/A';
  const date = new Date(dateString);
  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
};
</script>

<style scoped>
.credit-card-details {
  @apply bg-gray-50 dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700;
}
</style>
