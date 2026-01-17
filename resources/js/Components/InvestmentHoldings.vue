<template>
  <div v-if="isInvestmentAccount && hasHoldings" class="investment-holdings">
    <!-- Holdings Summary -->
    <div class="mb-3">
      <div class="flex justify-between items-center mb-1">
        <span class="text-xs font-medium text-gray-600 dark:text-gray-400">Investment Holdings</span>
        <span class="text-xs font-bold text-indigo-600 dark:text-indigo-400">
          {{ holdings.length }} {{ holdings.length === 1 ? 'position' : 'positions' }}
        </span>
      </div>
      
      <!-- Total Value -->
      <div class="bg-indigo-50 dark:bg-indigo-900/20 p-2 rounded-lg">
        <div class="flex justify-between items-center">
          <span class="text-xs text-gray-600 dark:text-gray-400">Total Value</span>
          <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">
            {{ formatCurrency(totalMarketValue) }}
          </span>
        </div>
        <div v-if="totalGainLoss !== null" class="flex justify-between items-center mt-1">
          <span class="text-xs text-gray-600 dark:text-gray-400">Total Gain/Loss</span>
          <span 
            class="text-xs font-semibold"
            :class="totalGainLoss >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
          >
            {{ totalGainLoss >= 0 ? '+' : '' }}{{ formatCurrency(totalGainLoss) }}
            <span v-if="totalGainLossPercent !== null" class="ml-1">
              ({{ totalGainLossPercent >= 0 ? '+' : '' }}{{ totalGainLossPercent.toFixed(2) }}%)
            </span>
          </span>
        </div>
      </div>
    </div>

    <!-- Holdings List -->
    <div class="space-y-2 max-h-64 overflow-y-auto">
      <div 
        v-for="holding in sortedHoldings" 
        :key="holding.id"
        class="bg-gray-50 dark:bg-gray-800 rounded-lg p-2 border border-gray-100 dark:border-gray-700"
      >
        <!-- Ticker and Name Row -->
        <div class="flex justify-between items-start mb-1">
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2">
              <span class="font-semibold text-sm text-gray-900 dark:text-gray-100">
                {{ holding.security?.ticker_symbol || 'N/A' }}
              </span>
              <span 
                v-if="holding.security?.is_cash_equivalent"
                class="px-1.5 py-0.5 text-[10px] font-medium bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300 rounded"
              >
                CASH
              </span>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 truncate">
              {{ holding.security?.name || 'Unknown Security' }}
            </p>
          </div>
          <div class="text-right flex-shrink-0">
            <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">
              {{ formatCurrency(holding.institution_value_cents) }}
            </div>
          </div>
        </div>
        
        <!-- Details Row -->
        <div class="flex justify-between items-center text-xs text-gray-500 dark:text-gray-400">
          <div class="flex items-center gap-3">
            <span>{{ formatQuantity(holding.quantity) }} shares</span>
            <span v-if="holding.institution_price_cents">
              @ {{ formatCurrency(holding.institution_price_cents) }}
            </span>
          </div>
          <div v-if="getHoldingGainLoss(holding) !== null">
            <span 
              class="font-medium"
              :class="getHoldingGainLoss(holding) >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
            >
              {{ getHoldingGainLoss(holding) >= 0 ? '+' : '' }}{{ formatCurrency(getHoldingGainLossCents(holding)) }}
              <span v-if="getHoldingGainLossPercent(holding) !== null">
                ({{ getHoldingGainLossPercent(holding) >= 0 ? '+' : '' }}{{ getHoldingGainLossPercent(holding).toFixed(1) }}%)
              </span>
            </span>
          </div>
        </div>
        
        <!-- Cost Basis Row (if available) -->
        <div v-if="holding.cost_basis_cents" class="flex justify-between items-center text-xs text-gray-400 dark:text-gray-500 mt-1">
          <span>Cost basis</span>
          <span>{{ formatCurrency(holding.cost_basis_cents) }}</span>
        </div>
      </div>
    </div>

    <!-- Last Updated -->
    <div v-if="holdingsUpdatedAt" class="mt-2 text-center">
      <span class="text-[10px] text-gray-400 dark:text-gray-500">
        Last updated: {{ formatDate(holdingsUpdatedAt) }}
      </span>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { formatCurrency } from '@/utils/format.js';

const props = defineProps({
  account: {
    type: Object,
    required: true,
  },
});

// Investment account subtypes (matches backend PlaidAccount::INVESTMENT_SUBTYPES)
const investmentSubtypes = [
  'brokerage', '401a', '401k', '403b', '457b', '529', 'cash isa', 'crypto exchange',
  'education savings account', 'fixed annuity', 'gic', 'health reimbursement arrangement',
  'hsa', 'ira', 'isa', 'keogh', 'lif', 'life insurance', 'lira', 'lrif', 'lrsp',
  'mutual fund', 'non-custodial wallet', 'non-taxable brokerage account', 'other',
  'other annuity', 'other insurance', 'pension', 'prif', 'profit sharing plan',
  'qshr', 'rdsp', 'resp', 'retirement', 'rlif', 'roth', 'roth 401k', 'rrif', 'rrsp',
  'sarsep', 'sep ira', 'simple ira', 'sipp', 'stock plan', 'tfsa', 'trust', 'ugma',
  'utma', 'variable annuity',
];

// Check if this is an investment account
const isInvestmentAccount = computed(() => {
  const plaidAccount = props.account?.plaid_account;
  if (!plaidAccount) return false;
  
  // Check main type first
  if (plaidAccount.account_type === 'investment') return true;
  
  // Also check if subtype is an investment subtype
  const subtype = (plaidAccount.account_subtype || '').toLowerCase();
  return investmentSubtypes.includes(subtype);
});

// Get holdings from account
const holdings = computed(() => {
  return props.account?.plaid_account?.holdings || [];
});

// Check if account has holdings
const hasHoldings = computed(() => holdings.value.length > 0);

// Sort holdings by market value (descending)
const sortedHoldings = computed(() => {
  return [...holdings.value].sort((a, b) => {
    return (b.institution_value_cents || 0) - (a.institution_value_cents || 0);
  });
});

// Calculate total market value
const totalMarketValue = computed(() => {
  return holdings.value.reduce((sum, h) => sum + (h.institution_value_cents || 0), 0);
});

// Calculate total cost basis
const totalCostBasis = computed(() => {
  const hasCostBasis = holdings.value.some(h => h.cost_basis_cents !== null);
  if (!hasCostBasis) return null;
  return holdings.value.reduce((sum, h) => sum + (h.cost_basis_cents || 0), 0);
});

// Calculate total gain/loss
const totalGainLoss = computed(() => {
  if (totalCostBasis.value === null) return null;
  return totalMarketValue.value - totalCostBasis.value;
});

// Calculate total gain/loss percentage
const totalGainLossPercent = computed(() => {
  if (totalCostBasis.value === null || totalCostBasis.value === 0) return null;
  return (totalGainLoss.value / totalCostBasis.value) * 100;
});

// Get most recent holdings update timestamp
const holdingsUpdatedAt = computed(() => {
  if (holdings.value.length === 0) return null;
  
  const dates = holdings.value
    .filter(h => h.updated_at)
    .map(h => new Date(h.updated_at));
  
  if (dates.length === 0) return null;
  return new Date(Math.max(...dates));
});

// Helper: Get gain/loss for a single holding (in cents)
const getHoldingGainLossCents = (holding) => {
  if (holding.institution_value_cents === null || holding.cost_basis_cents === null) return null;
  return holding.institution_value_cents - holding.cost_basis_cents;
};

// Helper: Get gain/loss for a single holding (boolean for positive/negative)
const getHoldingGainLoss = (holding) => {
  const gainLoss = getHoldingGainLossCents(holding);
  return gainLoss;
};

// Helper: Get gain/loss percentage for a single holding
const getHoldingGainLossPercent = (holding) => {
  if (holding.cost_basis_cents === null || holding.cost_basis_cents === 0) return null;
  const gainLoss = getHoldingGainLossCents(holding);
  if (gainLoss === null) return null;
  return (gainLoss / holding.cost_basis_cents) * 100;
};

// Format quantity with appropriate decimal places
const formatQuantity = (quantity) => {
  if (quantity === null || quantity === undefined) return '0';
  const num = parseFloat(quantity);
  // Show up to 4 decimal places, but remove trailing zeros
  return num.toLocaleString('en-US', { 
    minimumFractionDigits: 0, 
    maximumFractionDigits: 4 
  });
};

// Format date for display
const formatDate = (date) => {
  if (!date) return 'N/A';
  return new Date(date).toLocaleDateString('en-US', { 
    month: 'short', 
    day: 'numeric', 
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit'
  });
};
</script>

<style scoped>
.investment-holdings {
  @apply bg-gray-50 dark:bg-gray-800 rounded-lg p-3 border border-gray-200 dark:border-gray-700;
}
</style>
