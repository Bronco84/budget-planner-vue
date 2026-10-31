<template>
  <AuthenticatedLayout :title="`Edit ${plan.name} - ${budget.name}`">
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        Edit Payoff Plan
      </h2>
    </template>

    <div class="py-12">
      <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <form @submit.prevent="submit" class="p-6 space-y-6">
            <div>
              <label for="name" class="block text-sm font-medium text-gray-700">Plan Name</label>
              <input
                id="name"
                v-model="form.name"
                type="text"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                required
              />
              <p v-if="form.errors.name" class="mt-2 text-sm text-red-600">{{ form.errors.name }}</p>
            </div>

            <div>
              <label for="description" class="block text-sm font-medium text-gray-700">Description (Optional)</label>
              <textarea
                id="description"
                v-model="form.description"
                rows="3"
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
              ></textarea>
              <p v-if="form.errors.description" class="mt-2 text-sm text-red-600">{{ form.errors.description }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-3">Strategy</label>
              <div class="space-y-2">
                <label class="flex items-center">
                  <input
                    type="radio"
                    v-model="form.strategy"
                    value="snowball"
                    class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300"
                  />
                  <span class="ml-2 text-sm text-gray-700">Debt Snowball (smallest balance first)</span>
                </label>
                <label class="flex items-center">
                  <input
                    type="radio"
                    v-model="form.strategy"
                    value="avalanche"
                    class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300"
                  />
                  <span class="ml-2 text-sm text-gray-700">Debt Avalanche (highest interest first)</span>
                </label>
                <label class="flex items-center">
                  <input
                    type="radio"
                    v-model="form.strategy"
                    value="custom"
                    class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300"
                  />
                  <span class="ml-2 text-sm text-gray-700">Custom Priority</span>
                </label>
              </div>
              <p v-if="form.errors.strategy" class="mt-2 text-sm text-red-600">{{ form.errors.strategy }}</p>
            </div>

            <div>
              <label for="extra_payment" class="block text-sm font-medium text-gray-700">
                Monthly Extra Payment Towards Debt
              </label>
              <div class="mt-1 relative rounded-md shadow-sm">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <span class="text-gray-500 sm:text-sm">$</span>
                </div>
                <input
                  id="extra_payment"
                  v-model.number="extraPaymentDollars"
                  type="number"
                  step="0.01"
                  min="0"
                  class="block w-full pl-7 pr-12 rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                  required
                />
              </div>
              <p v-if="form.errors.monthly_extra_payment_cents" class="mt-2 text-sm text-red-600">
                {{ form.errors.monthly_extra_payment_cents }}
              </p>
            </div>

            <div>
              <h3 class="text-sm font-medium text-gray-700 mb-3">Debts</h3>
              <div class="space-y-3">
                <div
                  v-for="(debt, index) in form.debts"
                  :key="debt.id"
                  class="border rounded-lg p-4"
                >
                  <div class="flex items-center justify-between">
                    <span class="font-medium text-gray-900">{{ debt.name }}</span>
                    <span class="text-xs text-gray-500">Current balance {{ formatCurrency(debt.current_balance_cents) }}</span>
                  </div>
                  <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                      <label :for="`debt-apr-${debt.id}`" class="block text-xs font-medium text-gray-600">APR (%)</label>
                      <input
                        :id="`debt-apr-${debt.id}`"
                        v-model.number="debt.interest_rate"
                        type="number"
                        step="0.01"
                        min="0"
                        max="100"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        required
                      />
                      <p v-if="form.errors[`debts.${index}.interest_rate`]" class="mt-1 text-sm text-red-600">
                        {{ form.errors[`debts.${index}.interest_rate`] }}
                      </p>
                    </div>
                    <div>
                      <label :for="`debt-min-${debt.id}`" class="block text-xs font-medium text-gray-600">Minimum payment ($/month)</label>
                      <input
                        :id="`debt-min-${debt.id}`"
                        v-model.number="debt.minimum_payment_dollars"
                        type="number"
                        step="0.01"
                        min="0"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        required
                      />
                      <p v-if="form.errors[`debts.${index}.minimum_payment_cents`]" class="mt-1 text-sm text-red-600">
                        {{ form.errors[`debts.${index}.minimum_payment_cents`] }}
                      </p>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div v-if="form.goals.length > 0">
              <h3 class="text-sm font-medium text-gray-700">Goals</h3>
              <p class="mt-1 mb-3 text-xs text-gray-500">
                Goals are funded after every debt is paid off, lowest priority number first.
              </p>
              <div class="space-y-3">
                <div
                  v-for="(goal, index) in form.goals"
                  :key="goal.id"
                  class="border rounded-lg p-4 grid grid-cols-1 sm:grid-cols-2 gap-4"
                >
                  <div class="sm:col-span-2">
                    <label :for="`goal-name-${goal.id}`" class="block text-xs font-medium text-gray-600">Name</label>
                    <input
                      :id="`goal-name-${goal.id}`"
                      v-model="goal.name"
                      type="text"
                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                      required
                    />
                    <p v-if="form.errors[`goals.${index}.name`]" class="mt-1 text-sm text-red-600">
                      {{ form.errors[`goals.${index}.name`] }}
                    </p>
                  </div>
                  <div>
                    <label :for="`goal-target-${goal.id}`" class="block text-xs font-medium text-gray-600">Target ($)</label>
                    <input
                      :id="`goal-target-${goal.id}`"
                      v-model.number="goal.target_dollars"
                      type="number"
                      step="0.01"
                      min="0"
                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                      required
                    />
                    <p v-if="form.errors[`goals.${index}.target_amount_cents`]" class="mt-1 text-sm text-red-600">
                      {{ form.errors[`goals.${index}.target_amount_cents`] }}
                    </p>
                  </div>
                  <div>
                    <label :for="`goal-contribution-${goal.id}`" class="block text-xs font-medium text-gray-600">Monthly contribution ($)</label>
                    <input
                      :id="`goal-contribution-${goal.id}`"
                      v-model.number="goal.contribution_dollars"
                      type="number"
                      step="0.01"
                      min="0"
                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                      required
                    />
                    <p v-if="form.errors[`goals.${index}.monthly_contribution_cents`]" class="mt-1 text-sm text-red-600">
                      {{ form.errors[`goals.${index}.monthly_contribution_cents`] }}
                    </p>
                  </div>
                  <div>
                    <label :for="`goal-priority-${goal.id}`" class="block text-xs font-medium text-gray-600">Priority</label>
                    <input
                      :id="`goal-priority-${goal.id}`"
                      v-model.number="goal.priority"
                      type="number"
                      step="1"
                      min="0"
                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                    />
                    <p v-if="form.errors[`goals.${index}.priority`]" class="mt-1 text-sm text-red-600">
                      {{ form.errors[`goals.${index}.priority`] }}
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <div>
              <label class="flex items-center">
                <input
                  type="checkbox"
                  v-model="form.is_active"
                  class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
                />
                <span class="ml-2 text-sm text-gray-700">Set as active plan</span>
              </label>
              <p class="mt-1 text-xs text-gray-500">Only one plan can be active at a time</p>
            </div>

            <div class="flex items-center justify-between pt-4 border-t">
              <button
                type="button"
                @click="confirmDelete"
                class="inline-flex items-center px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition"
              >
                Delete Plan
              </button>

              <div class="flex space-x-3">
                <Link
                  :href="route('payoff-plans.show', [budget.id, plan.id])"
                  class="inline-flex items-center px-4 py-2 bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition"
                >
                  Cancel
                </Link>
                <button
                  type="submit"
                  :disabled="form.processing"
                  class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50 transition"
                >
                  Save Changes
                </button>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, watch } from 'vue';
import { useForm, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useToast } from '@/composables/useToast';
import { formatCurrency } from '@/utils/format.js';

const toast = useToast();

const props = defineProps({
  budget: Object,
  plan: Object,
});

const form = useForm({
  name: props.plan.name,
  description: props.plan.description,
  strategy: props.plan.strategy,
  monthly_extra_payment_cents: props.plan.monthly_extra_payment_cents,
  is_active: props.plan.is_active,
  debts: (props.plan.debts || []).map(debt => ({
    id: debt.id,
    name: debt.account?.name,
    current_balance_cents: debt.current_balance_cents,
    interest_rate: Number(debt.interest_rate),
    minimum_payment_dollars: debt.minimum_payment_cents / 100,
  })),
  goals: (props.plan.goals || []).map(goal => ({
    id: goal.id,
    name: goal.name,
    target_dollars: goal.target_amount_cents / 100,
    contribution_dollars: goal.monthly_contribution_cents / 100,
    priority: goal.priority ?? 0,
  })),
});

const toCents = (dollars) => Math.round((dollars || 0) * 100);

const extraPaymentDollars = ref(props.plan.monthly_extra_payment_cents / 100);

watch(extraPaymentDollars, (value) => {
  form.monthly_extra_payment_cents = Math.round((value || 0) * 100);
});

const submit = () => {
  form
    .transform((data) => ({
      ...data,
      debts: data.debts.map(debt => ({
        id: debt.id,
        interest_rate: debt.interest_rate,
        minimum_payment_cents: toCents(debt.minimum_payment_dollars),
      })),
      goals: data.goals.map(goal => ({
        id: goal.id,
        name: goal.name,
        target_amount_cents: toCents(goal.target_dollars),
        monthly_contribution_cents: toCents(goal.contribution_dollars),
        priority: goal.priority === '' ? null : goal.priority,
      })),
    }))
    .patch(route('payoff-plans.update', [props.budget.id, props.plan.id]));
};

const confirmDelete = async () => {
  const confirmed = await toast.confirm({
    title: 'Delete Payoff Plan',
    message: `Are you sure you want to delete the payoff plan "${props.plan.name}"? This cannot be undone.`,
    confirmText: 'Delete',
    cancelText: 'Cancel',
    type: 'danger'
  });
  
  if (confirmed) {
    router.delete(route('payoff-plans.destroy', [props.budget.id, props.plan.id]));
  }
};
</script>