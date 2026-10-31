<template>
  <div class="border border-gray-200 rounded-lg p-4 space-y-4">
    <div class="flex justify-between items-start">
      <h4 class="text-sm font-medium text-gray-700">Adjustment {{ index + 1 }}</h4>
      <button
        @click="$emit('remove')"
        type="button"
        class="text-gray-400 hover:text-red-600"
        title="Remove adjustment"
      >
        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <!-- Adjustment Type -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
        <select
          :value="modelValue.adjustment_type"
          @input="updateField('adjustment_type', $event.target.value)"
          class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
          required
        >
          <option value="">Select type...</option>
          <option value="one_time_expense">One-time Expense</option>
          <option value="recurring_expense">Recurring Expense</option>
          <option value="debt_paydown">Debt Paydown</option>
          <option value="savings_contribution">Savings Contribution</option>
          <option value="modify_existing">Modify Recurring Transaction</option>
        </select>
      </div>

      <!-- Recurring transaction to modify (modify existing) -->
      <div v-if="isModify">
        <label class="block text-sm font-medium text-gray-700 mb-1">Recurring Transaction</label>
        <select
          :value="modelValue.target_recurring_template_id"
          @input="selectTemplate($event.target.value)"
          class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
          required
        >
          <option value="">Select recurring transaction...</option>
          <option
            v-for="template in recurringTemplates"
            :key="template.id"
            :value="template.id"
          >
            {{ template.friendly_label || template.description }}{{ template.is_dynamic_amount ? '' : ` (${formatCurrency(template.amount_in_cents)})` }}
          </option>
        </select>
        <p v-if="recurringTemplates.length === 0" class="mt-1 text-xs text-gray-500">
          This budget has no recurring transactions to modify.
        </p>
      </div>

      <!-- Account -->
      <div v-if="!isModify">
        <label class="block text-sm font-medium text-gray-700 mb-1">Account</label>
        <select
          :value="modelValue.account_id"
          @input="updateField('account_id', parseInt($event.target.value))"
          class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
          required
        >
          <option value="">Select account...</option>
          <option
            v-for="account in filteredAccounts"
            :key="account.id"
            :value="account.id"
          >
            {{ account.name }}
          </option>
        </select>
      </div>

      <!-- Change (modify existing): a new amount or a percentage -->
      <div v-if="isModify">
        <label class="block text-sm font-medium text-gray-700 mb-1">Change</label>
        <div class="flex items-center space-x-4 mb-2">
          <label class="flex items-center text-sm text-gray-700">
            <input
              type="radio"
              :checked="!isPercentMode"
              @change="setModifyMode('amount')"
              class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300"
            />
            <span class="ml-2">New amount</span>
          </label>
          <label class="flex items-center text-sm text-gray-700">
            <input
              type="radio"
              :checked="isPercentMode"
              @change="setModifyMode('percent')"
              class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300"
            />
            <span class="ml-2">Percent change</span>
          </label>
        </div>
        <div v-if="isPercentMode" class="relative rounded-md shadow-sm">
          <input
            type="number"
            step="0.01"
            min="-100"
            :value="modelValue.percent_change"
            @input="updateField('percent_change', $event.target.value === '' ? 0 : parseFloat($event.target.value))"
            class="block w-full pr-8 border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
            placeholder="e.g. -10"
          />
          <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
            <span class="text-gray-500 sm:text-sm">%</span>
          </div>
        </div>
        <div v-else class="relative rounded-md shadow-sm">
          <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
            <span class="text-gray-500 sm:text-sm">$</span>
          </div>
          <input
            type="number"
            step="0.01"
            min="0"
            :value="modifyAmountInDollars"
            @input="updateAmount($event.target.value)"
            class="block w-full pl-7 pr-12 border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
            placeholder="0.00"
          />
        </div>
        <p class="mt-1 text-xs text-gray-500">
          {{ isPercentMode
            ? 'Scales each occurrence in the date range, e.g. -10 for 10% less.'
            : 'Replaces each occurrence in the date range; it stays income or expense like the original.' }}
        </p>
      </div>

      <!-- Amount -->
      <div v-else>
        <label class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
        <div class="relative rounded-md shadow-sm">
          <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
            <span class="text-gray-500 sm:text-sm">$</span>
          </div>
          <input
            type="number"
            step="0.01"
            :value="amountInDollars"
            @input="updateAmount($event.target.value)"
            class="block w-full pl-7 pr-12 border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
            placeholder="0.00"
            required
          />
        </div>
        <p class="mt-1 text-xs text-gray-500">
          Use positive values for income/deposits, negative values for expenses/withdrawals
        </p>
      </div>

      <!-- Start Date -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">
          {{ isOneTime ? 'Date' : 'Start Date' }}
        </label>
        <input
          type="date"
          :value="modelValue.start_date"
          @input="updateField('start_date', $event.target.value)"
          class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
          required
        />
      </div>

      <!-- End Date (for recurring) -->
      <div v-if="!isOneTime">
        <label class="block text-sm font-medium text-gray-700 mb-1">End Date (Optional)</label>
        <input
          type="date"
          :value="modelValue.end_date"
          @input="updateField('end_date', $event.target.value)"
          class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
        />
      </div>

      <!-- Frequency (for recurring) -->
      <div v-if="!isOneTime && !isModify">
        <label class="block text-sm font-medium text-gray-700 mb-1">Frequency</label>
        <select
          :value="modelValue.frequency"
          @input="updateField('frequency', $event.target.value)"
          class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
          required
        >
          <option value="">Select frequency...</option>
          <option value="daily">Daily</option>
          <option value="weekly">Weekly</option>
          <option value="biweekly">Every Two Weeks</option>
          <option value="monthly">Monthly</option>
          <option value="bimonthly">Twice a Month</option>
          <option value="quarterly">Quarterly</option>
          <option value="yearly">Yearly</option>
        </select>
      </div>

      <!-- Day of Week (for weekly/biweekly) -->
      <div v-if="!isModify && needsDayOfWeek">
        <label class="block text-sm font-medium text-gray-700 mb-1">Day of Week</label>
        <select
          :value="modelValue.day_of_week"
          @input="updateField('day_of_week', parseInt($event.target.value))"
          class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
        >
          <option value="">Select day...</option>
          <option :value="0">Sunday</option>
          <option :value="1">Monday</option>
          <option :value="2">Tuesday</option>
          <option :value="3">Wednesday</option>
          <option :value="4">Thursday</option>
          <option :value="5">Friday</option>
          <option :value="6">Saturday</option>
        </select>
      </div>

      <!-- First Day of Month (for bimonthly / twice a month) -->
      <div v-if="!isModify && isBimonthly">
        <label class="block text-sm font-medium text-gray-700 mb-1">First Day of Month</label>
        <input
          type="number"
          min="1"
          max="31"
          :value="modelValue.first_day_of_month"
          @input="updateField('first_day_of_month', parseInt($event.target.value))"
          class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
          placeholder="1-31"
        />
      </div>

      <!-- Day of Month (for monthly/quarterly/yearly; second day for bimonthly) -->
      <div v-if="!isModify && needsDayOfMonth">
        <label class="block text-sm font-medium text-gray-700 mb-1">{{ isBimonthly ? 'Second Day of Month' : 'Day of Month' }}</label>
        <input
          type="number"
          min="1"
          max="31"
          :value="modelValue.day_of_month"
          @input="updateField('day_of_month', parseInt($event.target.value))"
          class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
          placeholder="1-31"
        />
      </div>
    </div>

    <!-- Description -->
    <div>
      <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
      <input
        type="text"
        :value="modelValue.description"
        @input="updateField('description', $event.target.value)"
        class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
        placeholder="What is this adjustment for?"
      />
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { formatCurrency } from '@/utils/format.js';

const props = defineProps({
  modelValue: {
    type: Object,
    required: true,
  },
  index: {
    type: Number,
    required: true,
  },
  accounts: {
    type: Array,
    default: () => [],
  },
  recurringTemplates: {
    type: Array,
    default: () => [],
  },
});

const emit = defineEmits(['update:modelValue', 'remove']);

const updateField = (field, value) => {
  emit('update:modelValue', {
    ...props.modelValue,
    [field]: value,
  });
};

const amountInDollars = computed(() => {
  if (!props.modelValue.amount_in_cents && props.modelValue.amount_in_cents !== 0) return '';
  return props.modelValue.amount_in_cents / 100;
});

const updateAmount = (value) => {
  const dollars = parseFloat(value) || 0;
  const cents = Math.round(dollars * 100);
  updateField('amount_in_cents', cents);
};

const isModify = computed(() => props.modelValue.adjustment_type === 'modify_existing');

const isPercentMode = computed(() => props.modelValue.percent_change !== null && props.modelValue.percent_change !== undefined);

const modifyAmountInDollars = computed(() => {
  if (!props.modelValue.amount_in_cents && props.modelValue.amount_in_cents !== 0) return '';
  return Math.abs(props.modelValue.amount_in_cents) / 100;
});

// Picking the template also sets the adjustment's account to the template's account.
const selectTemplate = (value) => {
  const templateId = value ? parseInt(value) : '';
  const template = props.recurringTemplates.find(t => t.id === templateId);
  emit('update:modelValue', {
    ...props.modelValue,
    target_recurring_template_id: templateId,
    account_id: template?.account_id ?? props.modelValue.account_id,
  });
};

const setModifyMode = (mode) => {
  emit('update:modelValue', {
    ...props.modelValue,
    percent_change: mode === 'percent' ? (props.modelValue.percent_change ?? 0) : null,
    amount_in_cents: mode === 'percent' ? 0 : props.modelValue.amount_in_cents,
  });
};

const isOneTime = computed(() => {
  return props.modelValue.adjustment_type === 'one_time_expense';
});

const isExpenseType = computed(() => {
  return ['one_time_expense', 'recurring_expense', 'debt_paydown'].includes(
    props.modelValue.adjustment_type
  );
});

const needsDayOfWeek = computed(() => {
  return ['weekly', 'biweekly'].includes(props.modelValue.frequency);
});

const isBimonthly = computed(() => props.modelValue.frequency === 'bimonthly');

const needsDayOfMonth = computed(() => {
  return ['monthly', 'bimonthly', 'quarterly', 'yearly'].includes(props.modelValue.frequency);
});

const filteredAccounts = computed(() => {
  // For debt paydown, only show liability accounts
  if (props.modelValue.adjustment_type === 'debt_paydown') {
    return props.accounts.filter(account => 
      ['credit card', 'credit', 'loan', 'line of credit', 'mortgage'].includes(account.type)
    );
  }
  // For savings contribution, only show asset accounts
  if (props.modelValue.adjustment_type === 'savings_contribution') {
    return props.accounts.filter(account => 
      !['credit card', 'credit', 'loan', 'line of credit', 'mortgage'].includes(account.type)
    );
  }
  // For other types, show all accounts
  return props.accounts;
});
</script>

