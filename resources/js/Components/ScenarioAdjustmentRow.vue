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
          <option value="modify_existing">Modify Existing Transaction</option>
        </select>
      </div>

      <!-- Account -->
      <div>
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

      <!-- Amount -->
      <div>
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
      <div v-if="!isOneTime">
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
          <option value="quarterly">Quarterly</option>
          <option value="yearly">Yearly</option>
        </select>
      </div>

      <!-- Day of Week (for weekly/biweekly) -->
      <div v-if="needsDayOfWeek">
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

      <!-- Day of Month (for monthly/quarterly/yearly) -->
      <div v-if="needsDayOfMonth">
        <label class="block text-sm font-medium text-gray-700 mb-1">Day of Month</label>
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

const needsDayOfMonth = computed(() => {
  return ['monthly', 'quarterly', 'yearly'].includes(props.modelValue.frequency);
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

