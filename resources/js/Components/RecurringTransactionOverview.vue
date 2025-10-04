<template>
  <div class="space-y-6">
    <!-- Transaction Details -->
    <div>
      <h3 class="text-lg font-medium text-gray-900 mb-4">Transaction Details</h3>

      <form @submit.prevent="submit">
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
            />
            <InputError class="mt-2" :message="form.errors.category" />
          </div>

          <!-- Amount Type -->
          <div>
            <InputLabel value="Amount Type" />
            <div class="mt-2 space-y-2">
              <div class="flex items-center">
                <input
                  id="static-amount"
                  type="radio"
                  v-model="form.is_dynamic_amount"
                  :value="false"
                  class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300"
                />
                <label for="static-amount" class="ml-2 block text-sm text-gray-700">
                  Static Amount
                </label>
              </div>
              <div class="flex items-center">
                <input
                  id="dynamic-amount"
                  type="radio"
                  v-model="form.is_dynamic_amount"
                  :value="true"
                  class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300"
                />
                <label for="dynamic-amount" class="ml-2 block text-sm text-gray-700">
                  Dynamic Amount
                </label>
              </div>
            </div>
          </div>

          <!-- Amount -->
          <div>
            <InputLabel for="amount" value="Amount" />
            <div class="mt-1 relative rounded-md shadow-sm">
              <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <span class="text-gray-500 sm:text-sm">$</span>
              </div>
              <input
                id="amount"
                type="number"
                step="0.01"
                v-model="form.amount"
                class="focus:ring-indigo-500 focus:border-indigo-500 block w-full pl-7 pr-12 sm:text-sm border-gray-300 rounded-md"
                required
              />
            </div>
            <p class="mt-1 text-sm text-gray-500">Use positive values for income, negative for expenses (e.g., -50.00)</p>
            <InputError class="mt-2" :message="form.errors.amount" />
          </div>

          <!-- Notes -->
          <div class="md:col-span-2">
            <InputLabel for="notes" value="Notes (Optional)" />
            <textarea
              id="notes"
              v-model="form.notes"
              rows="3"
              class="mt-1 shadow-sm focus:ring-indigo-500 focus:border-indigo-500 block w-full sm:text-sm border-gray-300 rounded-md"
            ></textarea>
            <InputError class="mt-2" :message="form.errors.notes" />
          </div>
        </div>

        <div class="mt-6 border-t border-gray-200 pt-6">
          <h4 class="text-md font-medium text-gray-900 mb-4">Recurring Options</h4>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Frequency -->
            <div>
              <InputLabel for="frequency" value="Frequency" />
              <SelectInput
                id="frequency"
                v-model="form.frequency"
                class="mt-1 block w-full"
                required
              >
                <option value="" disabled>Select frequency</option>
                <option value="daily">Daily</option>
                <option value="weekly">Weekly</option>
                <option value="biweekly">Every Two Weeks</option>
                <option value="monthly">Monthly</option>
                <option value="bimonthly">Twice a Month</option>
                <option value="quarterly">Quarterly</option>
                <option value="yearly">Yearly</option>
              </SelectInput>
              <InputError class="mt-2" :message="form.errors.frequency" />
            </div>

            <!-- Day of Week (for weekly/biweekly) -->
            <div v-if="form.frequency === 'weekly' || form.frequency === 'biweekly'">
              <InputLabel for="day_of_week" value="Day of Week" />
              <SelectInput
                id="day_of_week"
                v-model="form.day_of_week"
                class="mt-1 block w-full"
              >
                <option value="" disabled>Select day of week</option>
                <option value="0">Sunday</option>
                <option value="1">Monday</option>
                <option value="2">Tuesday</option>
                <option value="3">Wednesday</option>
                <option value="4">Thursday</option>
                <option value="5">Friday</option>
                <option value="6">Saturday</option>
              </SelectInput>
              <p class="mt-1 text-sm text-gray-500">The day of the week when the transaction occurs</p>
              <InputError class="mt-2" :message="form.errors.day_of_week" />
            </div>

            <!-- Day of Month (for monthly) -->
            <div v-if="form.frequency === 'monthly'">
              <InputLabel for="day_of_month" value="Day of Month" />
              <input
                id="day_of_month"
                type="number"
                min="1"
                max="31"
                v-model="form.day_of_month"
                class="mt-1 focus:ring-indigo-500 focus:border-indigo-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-md"
              />
              <p class="mt-1 text-sm text-gray-500">The day of the month when the transaction occurs (1-31)</p>
              <InputError class="mt-2" :message="form.errors.day_of_month" />
            </div>

            <!-- Start Date -->
            <div>
              <InputLabel for="start_date" value="Start Date" />
              <TextInput
                id="start_date"
                type="date"
                class="mt-1 block w-full"
                v-model="form.start_date"
                required
              />
              <InputError class="mt-2" :message="form.errors.start_date" />
            </div>

            <!-- End Date -->
            <div>
              <InputLabel for="end_date" value="End Date (Optional)" />
              <TextInput
                id="end_date"
                type="date"
                class="mt-1 block w-full"
                v-model="form.end_date"
              />
              <InputError class="mt-2" :message="form.errors.end_date" />
            </div>
          </div>
        </div>

        <!-- Action Buttons -->
        <div class="mt-6 flex items-center justify-between">
          <button
            type="button"
            @click="confirmDelete"
            class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-700 active:bg-red-800 focus:outline-none focus:border-red-900 focus:ring focus:ring-red-300 disabled:opacity-25 transition"
          >
            Delete
          </button>
          <div class="flex space-x-3">
            <Link
              :href="route('recurring-transactions.index', budget.id)"
              class="inline-flex items-center px-4 py-2 bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-300 active:bg-gray-400 focus:outline-none focus:border-gray-900 focus:ring focus:ring-gray-300 disabled:opacity-25 transition"
            >
              Cancel
            </Link>
            <button
              type="submit"
              :disabled="form.processing"
              class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 active:bg-gray-900 focus:outline-none focus:border-gray-900 focus:ring focus:ring-gray-300 disabled:opacity-25 transition"
            >
              Update Recurring Transaction
            </button>
          </div>
        </div>
      </form>
    </div>

    <!-- Quick Stats -->
    <div class="border-t border-gray-200 pt-6">
      <h3 class="text-lg font-medium text-gray-900 mb-4">Quick Stats</h3>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-gray-50 p-4 rounded-lg">
          <div class="text-sm text-gray-600">Total Transactions</div>
          <div class="text-xl font-bold text-gray-900">{{ linkedTransactions.length }}</div>
          <div v-if="linkedTransactions.length > 0" class="text-xs text-gray-500 mt-1">
            Last: {{ formatDate(linkedTransactions[0]?.date) }}
          </div>
        </div>
        <div class="bg-gray-50 p-4 rounded-lg">
          <div class="text-sm text-gray-600">Active Rules</div>
          <div class="text-xl font-bold text-gray-900">{{ activeRulesCount }}</div>
          <div class="text-xs text-gray-500 mt-1">{{ rules.length }} total rules</div>
        </div>
        <div class="bg-gray-50 p-4 rounded-lg">
          <div class="text-sm text-gray-600">Total Amount</div>
          <div class="text-xl font-bold" :class="totalAmount >= 0 ? 'text-green-600' : 'text-red-600'">
            {{ formatCurrency(totalAmount) }}
          </div>
          <div class="text-xs text-gray-500 mt-1">From linked transactions</div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useForm, Link, router } from '@inertiajs/vue3';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import SelectInput from '@/Components/SelectInput.vue';
import InputError from '@/Components/InputError.vue';
import { formatCurrency } from '@/utils/format.js';

const props = defineProps({
  budget: Object,
  recurringTransaction: Object,
  accounts: Array,
  linkedTransactions: Array,
  rules: Array,
});

const form = useForm({
  account_id: props.recurringTransaction.account_id,
  description: props.recurringTransaction.description,
  category: props.recurringTransaction.category,
  amount: props.recurringTransaction.amount_in_cents / 100,
  is_dynamic_amount: props.recurringTransaction.is_dynamic_amount,
  frequency: props.recurringTransaction.frequency,
  day_of_week: props.recurringTransaction.day_of_week,
  day_of_month: props.recurringTransaction.day_of_month,
  start_date: props.recurringTransaction.start_date,
  end_date: props.recurringTransaction.end_date,
  notes: props.recurringTransaction.notes,
});

const activeRulesCount = computed(() => {
  return props.rules.filter(r => r.is_active).length;
});

const totalAmount = computed(() => {
  return props.linkedTransactions.reduce((sum, t) => sum + t.amount_in_cents, 0);
});

const formatDate = (dateString) => {
  if (!dateString) return 'N/A';
  const date = new Date(dateString);
  return date.toLocaleDateString();
};

const submit = () => {
  form.patch(route('recurring-transactions.update', {
    budget: props.budget.id,
    recurring_transaction: props.recurringTransaction.id
  }));
};

const confirmDelete = () => {
  if (confirm(`Are you sure you want to delete the recurring transaction "${props.recurringTransaction.description}"?`)) {
    router.delete(route('recurring-transactions.destroy', [props.budget.id, props.recurringTransaction.id]));
  }
};
</script>