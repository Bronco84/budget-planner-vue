<template>
  <AuthenticatedLayout :title="`Debt Payoff Plans - ${budget.name}`">
    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <!-- Page Header with Action -->
        <div class="flex items-center justify-between mb-6">
          <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Debt Payoff Plans
          </h2>
          <Link
            :href="route('payoff-plans.create', budget.id)"
            class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:border-indigo-900 focus:ring focus:ring-indigo-300 disabled:opacity-25 transition"
          >
            Create New Plan
          </Link>
        </div>
        <!-- Empty State -->
        <div v-if="plans.length === 0" class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-12 text-center">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">No payoff plans</h3>
            <p class="mt-1 text-sm text-gray-500">Get started by creating a debt payoff plan.</p>
            <div class="mt-6">
              <Link
                :href="route('payoff-plans.create', budget.id)"
                class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700"
              >
                Create New Plan
              </Link>
            </div>
          </div>
        </div>

        <!-- Plans List -->
        <div v-else class="space-y-6">
          <div
            v-for="plan in plans"
            :key="plan.id"
            class="bg-white overflow-hidden shadow-sm sm:rounded-lg hover:shadow-md transition"
          >
            <div class="p-6">
              <div class="flex items-start justify-between">
                <div class="flex-1">
                  <div class="flex items-center space-x-3">
                    <h3 class="text-lg font-semibold text-gray-900">{{ plan.name }}</h3>
                    <span
                      v-if="plan.is_active"
                      class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800"
                    >
                      Active
                    </span>
                  </div>
                  <p v-if="plan.description" class="mt-1 text-sm text-gray-500">{{ plan.description }}</p>

                  <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                    <!-- Strategy -->
                    <div class="flex items-center space-x-2">
                      <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                      </svg>
                      <div>
                        <div class="text-xs text-gray-500">Strategy</div>
                        <div class="text-sm font-medium text-gray-900 capitalize">{{ plan.strategy }}</div>
                      </div>
                    </div>

                    <!-- Debts Count -->
                    <div class="flex items-center space-x-2">
                      <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                      </svg>
                      <div>
                        <div class="text-xs text-gray-500">Debts</div>
                        <div class="text-sm font-medium text-gray-900">{{ plan.debts.length }} accounts</div>
                      </div>
                    </div>

                    <!-- Extra Payment -->
                    <div class="flex items-center space-x-2">
                      <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                      <div>
                        <div class="text-xs text-gray-500">Extra Payment</div>
                        <div class="text-sm font-medium text-gray-900">{{ formatCurrency(plan.monthly_extra_payment_cents) }}/mo</div>
                      </div>
                    </div>
                  </div>

                  <!-- Goals -->
                  <div v-if="plan.goals && plan.goals.length > 0" class="mt-4">
                    <div class="text-xs text-gray-500 mb-2">Financial Goals</div>
                    <div class="flex flex-wrap gap-2">
                      <span
                        v-for="goal in plan.goals"
                        :key="goal.id"
                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800"
                      >
                        {{ goal.name }}
                      </span>
                    </div>
                  </div>
                </div>

                <!-- Actions -->
                <div class="ml-4 flex-shrink-0 space-x-2">
                  <Link
                    :href="route('payoff-plans.show', [budget.id, plan.id])"
                    class="inline-flex items-center px-3 py-2 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                  >
                    View Details
                  </Link>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatCurrency } from '@/utils/format.js';

const props = defineProps({
  budget: Object,
  plans: Array,
});
</script>