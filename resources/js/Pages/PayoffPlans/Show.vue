<template>
  <AuthenticatedLayout :title="`${plan.name} - ${budget.name}`">
    <template #header>
      <div class="flex items-center justify-between">
        <div>
          <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ plan.name }}
          </h2>
          <p v-if="plan.description" class="mt-1 text-sm text-gray-500">{{ plan.description }}</p>
        </div>
        <div class="flex space-x-2">
          <Link
            :href="route('payoff-plans.edit', [budget.id, plan.id])"
            class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition"
          >
            Edit
          </Link>
          <Link
            :href="route('payoff-plans.index', budget.id)"
            class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition"
          >
            Back to Plans
          </Link>
        </div>
      </div>
    </template>

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <!-- Key Metrics -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
          <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
            <div class="text-sm text-gray-500">Payoff Time</div>
            <div class="mt-2 text-2xl font-bold text-gray-900">
              {{ Math.ceil(debtProjection.total_months / 12) }} years
            </div>
            <div class="text-xs text-gray-500">{{ debtProjection.total_months }} months</div>
          </div>

          <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
            <div class="text-sm text-gray-500">Payoff Date</div>
            <div class="mt-2 text-2xl font-bold text-gray-900">
              {{ formatPayoffDate(debtProjection.payoff_date) }}
            </div>
          </div>

          <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
            <div class="text-sm text-gray-500">Total Interest</div>
            <div class="mt-2 text-2xl font-bold text-red-600">
              {{ formatCurrency(debtProjection.total_interest_paid) }}
            </div>
          </div>

          <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
            <div class="text-sm text-gray-500">Strategy</div>
            <div class="mt-2 text-2xl font-bold text-gray-900 capitalize">
              {{ plan.strategy }}
            </div>
          </div>
        </div>

        <!-- Debts Overview -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Debts in This Plan</h3>
            <div class="space-y-4">
              <div
                v-for="debt in plan.debts"
                :key="debt.id"
                class="border rounded-lg p-4"
              >
                <div class="flex items-center justify-between">
                  <div class="flex-1">
                    <h4 class="font-medium text-gray-900">{{ debt.account.name }}</h4>
                    <div class="mt-1 text-sm text-gray-500">
                      {{ debt.interest_rate }}% APR • Min payment: {{ formatCurrency(debt.minimum_payment_cents) }}/month
                    </div>
                  </div>
                  <div class="text-right">
                    <div class="text-lg font-bold text-red-600">
                      {{ formatCurrency(debt.starting_balance_cents) }}
                    </div>
                    <div class="text-xs text-gray-500">Starting balance</div>
                  </div>
                </div>

                <!-- Progress bar for this debt -->
                <div class="mt-4">
                  <div class="flex items-center justify-between text-xs text-gray-500 mb-1">
                    <span>Progress</span>
                    <span>{{ getDebtProgress(debt.id) }}%</span>
                  </div>
                  <div class="w-full bg-gray-200 rounded-full h-2">
                    <div
                      class="bg-green-600 h-2 rounded-full transition-all duration-500"
                      :style="{ width: `${getDebtProgress(debt.id)}%` }"
                    ></div>
                  </div>
                  <div class="mt-1 text-xs text-gray-500">
                    Estimated payoff: {{ getDebtPayoffMonth(debt.id) }}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Timeline Visualization -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Payoff Timeline</h3>

            <!-- Show every 6 months for first 5 years, then yearly -->
            <div class="space-y-3">
              <div
                v-for="(milestone, index) in timelineMilestones"
                :key="index"
                class="flex items-center"
              >
                <div class="flex-shrink-0 w-24 text-sm text-gray-600">
                  {{ milestone.label }}
                </div>
                <div class="flex-1 ml-4">
                  <div class="flex items-center space-x-2">
                    <div class="flex-1 bg-gray-200 rounded-full h-8 relative overflow-hidden">
                      <!-- Debt segments -->
                      <div class="flex h-full">
                        <div
                          v-for="(segment, debtIndex) in milestone.segments"
                          :key="debtIndex"
                          :style="{
                            width: segment.width + '%',
                            backgroundColor: getDebtColor(debtIndex)
                          }"
                          class="h-full transition-all duration-500"
                          :title="segment.name + ': ' + formatCurrency(segment.balance)"
                        ></div>
                      </div>
                    </div>
                    <div class="flex-shrink-0 w-32 text-right text-sm font-medium text-gray-900">
                      {{ formatCurrency(milestone.total_balance) }}
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Legend -->
            <div class="mt-6 flex flex-wrap gap-4">
              <div
                v-for="(debt, index) in plan.debts"
                :key="debt.id"
                class="flex items-center space-x-2"
              >
                <div
                  class="w-4 h-4 rounded"
                  :style="{ backgroundColor: getDebtColor(index) }"
                ></div>
                <span class="text-sm text-gray-600">{{ debt.account.name }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Financial Goals -->
        <div v-if="goalProjections.length > 0" class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Financial Goals</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div
                v-for="goalProj in goalProjections"
                :key="goalProj.goal_id"
                class="border rounded-lg p-4"
              >
                <h4 class="font-medium text-gray-900">{{ goalProj.name }}</h4>
                <div class="mt-2 space-y-1">
                  <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-500">Target</span>
                    <span class="font-medium text-gray-900">{{ formatCurrency(goalProj.target_amount) }}</span>
                  </div>
                  <div class="flex items-center justify-between text-sm">
                    <span class="text-gray-500">Monthly</span>
                    <span class="font-medium text-gray-900">{{ formatCurrency(goalProj.monthly_contribution) }}</span>
                  </div>
                  <div v-if="goalProj.months_to_complete" class="flex items-center justify-between text-sm">
                    <span class="text-gray-500">Complete By</span>
                    <span class="font-medium text-green-600">{{ formatDate(goalProj.completion_date) }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Monthly Breakdown (first 12 months) -->
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">First 12 Months Breakdown</h3>
            <div class="overflow-x-auto">
              <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                  <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Month</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Payment</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Extra</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Balance</th>
                  </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                  <tr v-for="month in first12Months" :key="month.month">
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                      Month {{ month.month }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900">
                      {{ formatCurrency(month.total_payment) }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-green-600">
                      {{ formatCurrency(month.extra_payment_used) }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-medium text-gray-900">
                      {{ formatCurrency(month.total_balance) }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatCurrency } from '@/utils/format.js';

const props = defineProps({
  budget: Object,
  plan: Object,
  debtProjection: Object,
  goalProjections: Array,
});

const first12Months = computed(() => {
  return props.debtProjection.timeline.slice(0, 12);
});

const timelineMilestones = computed(() => {
  const milestones = [];
  const timeline = props.debtProjection.timeline;

  // Month 0 (start)
  milestones.push({
    label: 'Start',
    month: 0,
    total_balance: props.plan.debts.reduce((sum, d) => sum + d.starting_balance_cents, 0),
    segments: props.plan.debts.map(debt => ({
      name: debt.account.name,
      balance: debt.starting_balance_cents,
      width: 0
    }))
  });

  // Calculate total starting balance
  const totalStart = props.plan.debts.reduce((sum, d) => sum + d.starting_balance_cents, 0);

  // Initial segments
  milestones[0].segments = props.plan.debts.map(debt => ({
    name: debt.account.name,
    balance: debt.starting_balance_cents,
    width: (debt.starting_balance_cents / totalStart) * 100
  }));

  // Every 6 months for first 5 years
  for (let i = 6; i <= 60; i += 6) {
    if (i < timeline.length) {
      const month = timeline[i];
      milestones.push(createMilestone(`${i} mo`, i, month));
    }
  }

  // Then yearly milestones
  for (let year = 6; year * 12 < timeline.length; year++) {
    const monthIndex = year * 12;
    if (monthIndex < timeline.length) {
      const month = timeline[monthIndex];
      milestones.push(createMilestone(`${year} yr`, monthIndex, month));
    }
  }

  // Final month
  if (timeline.length > 0) {
    const lastMonth = timeline[timeline.length - 1];
    milestones.push(createMilestone('End', timeline.length, lastMonth));
  }

  return milestones;
});

function createMilestone(label, monthIndex, monthData) {
  const totalBalance = monthData.total_balance;
  const segments = monthData.debts.map(debt => ({
    name: debt.name,
    balance: debt.balance,
    width: totalBalance > 0 ? (debt.balance / totalBalance) * 100 : 0
  }));

  return {
    label,
    month: monthIndex,
    total_balance: totalBalance,
    segments
  };
}

const getDebtColor = (index) => {
  const colors = [
    '#EF4444', // red-500
    '#F59E0B', // amber-500
    '#10B981', // green-500
    '#3B82F6', // blue-500
    '#8B5CF6', // purple-500
    '#EC4899', // pink-500
  ];
  return colors[index % colors.length];
};

const getDebtProgress = (debtId) => {
  // Find the debt in the final projection data
  const debt = props.debtProjection.final_debt_data.find(d => d.id === debtId);
  if (!debt) return 0;

  const originalBalance = props.plan.debts.find(d => d.id === debtId)?.starting_balance_cents || 0;
  if (originalBalance === 0) return 0;

  const remaining = debt.balance;
  const paid = originalBalance - remaining;
  return Math.min(Math.round((paid / originalBalance) * 100), 100);
};

const getDebtPayoffMonth = (debtId) => {
  // Find when this debt reaches 0
  const timeline = props.debtProjection.timeline;
  for (let i = 0; i < timeline.length; i++) {
    const month = timeline[i];
    const debtData = month.debts.find(d => d.id === debtId);
    if (debtData && debtData.balance === 0) {
      const years = Math.floor(i / 12);
      const months = i % 12;
      if (years === 0) {
        return `${months} month${months !== 1 ? 's' : ''}`;
      } else if (months === 0) {
        return `${years} year${years !== 1 ? 's' : ''}`;
      } else {
        return `${years} year${years !== 1 ? 's' : ''}, ${months} month${months !== 1 ? 's' : ''}`;
      }
    }
  }
  return 'Unknown';
};

const formatPayoffDate = (dateString) => {
  if (!dateString) return 'N/A';
  const date = new Date(dateString);
  return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long' });
};

const formatDate = (dateString) => {
  if (!dateString) return 'N/A';
  const date = new Date(dateString);
  return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
};
</script>