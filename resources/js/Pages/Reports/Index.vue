<script setup>
import { ref, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import OverviewTab from './Tabs/OverviewTab.vue';
import NetWorthTab from './Tabs/NetWorthTab.vue';
import CashFlowTab from './Tabs/CashFlowTab.vue';
import BudgetPerformanceTab from './Tabs/BudgetPerformanceTab.vue';
import SpendingPatternsTab from './Tabs/SpendingPatternsTab.vue';
import DebtPayoffTab from './Tabs/DebtPayoffTab.vue';
import DateRangePicker from '@/Components/DateRangePicker.vue';

const props = defineProps({
    budget: Object,
    dateRange: Object,
    overview: Object,
    netWorth: Object,
    cashFlow: Object,
    budgetPerformance: Object,
    spendingPatterns: Object,
    debtPayoff: Object,
});

const tabs = [
    { name: 'Overview', component: 'overview' },
    { name: 'Net Worth', component: 'netWorth' },
    { name: 'Cash Flow', component: 'cashFlow' },
    { name: 'Budget Performance', component: 'budgetPerformance' },
    { name: 'Spending Patterns', component: 'spendingPatterns' },
    { name: 'Debt Payoff', component: 'debtPayoff' },
];

const activeTab = ref('overview');

const activeComponent = computed(() => {
    switch (activeTab.value) {
        case 'overview':
            return OverviewTab;
        case 'netWorth':
            return NetWorthTab;
        case 'cashFlow':
            return CashFlowTab;
        case 'budgetPerformance':
            return BudgetPerformanceTab;
        case 'spendingPatterns':
            return SpendingPatternsTab;
        case 'debtPayoff':
            return DebtPayoffTab;
        default:
            return OverviewTab;
    }
});

const handleDateRangeChange = (dateRangeData) => {
    router.get(route('reports.index', props.budget.id), dateRangeData, {
        preserveState: true,
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="`Reports - ${budget.name}`" />

    <AuthenticatedLayout>
        <div class="py-4">
            <div class="max-w-8xl mx-auto sm:px-2 lg:px-4">
                <!-- White Container with Integrated Tabs -->
                <div class="bg-white dark:bg-gray-800 shadow-sm rounded-lg">
                    <!-- Tabs as header inside container -->
                    <div class="border-b border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between px-6">
                            <!-- Tabs (left side) -->
                            <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                                <button
                                    v-for="tab in tabs"
                                    :key="tab.component"
                                    @click="activeTab = tab.component"
                                    :class="[
                                        activeTab === tab.component
                                            ? 'border-blue-500 text-blue-600 dark:text-blue-400'
                                            : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300',
                                        'whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium transition-colors'
                                    ]"
                                >
                                    {{ tab.name }}
                                </button>
                            </nav>

                            <!-- Date Range Picker (right side) -->
                            <div class="">
                                <DateRangePicker
                                    :start-date="dateRange.start"
                                    :end-date="dateRange.end"
                                    :preset="dateRange.preset"
                                    @update="handleDateRangeChange"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Tab Content -->
                    <div class="p-6">
                        <component
                            :is="activeComponent"
                            :budget="budget"
                            :date-range="dateRange"
                            :overview="overview"
                            :net-worth="netWorth"
                            :cash-flow="cashFlow"
                            :budget-performance="budgetPerformance"
                            :spending-patterns="spendingPatterns"
                            :debt-payoff="debtPayoff"
                        />
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
