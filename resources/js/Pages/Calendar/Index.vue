<script setup>
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CalendarGrid from '@/Components/Calendar/CalendarGrid.vue';
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/24/outline';
import { formatCurrency } from '@/utils/format.js';

const props = defineProps({
    budgets: Array,
    selectedBudget: Object,
    calendarData: Object,
    currentMonth: String,
    filters: Object
});

const selectedDay = ref(null);
const showDayModal = ref(false);

// Parse current month/year from prop
const currentDate = computed(() => {
    const [year, month] = props.currentMonth.split('-');
    return { year: parseInt(year), month: parseInt(month) };
});

// Navigation functions
const navigateToMonth = (offset) => {
    let newMonth = currentDate.value.month + offset;
    let newYear = currentDate.value.year;

    if (newMonth > 12) {
        newMonth = 1;
        newYear++;
    } else if (newMonth < 1) {
        newMonth = 12;
        newYear--;
    }

    router.get(route('calendar.index'), {
        year: newYear,
        month: newMonth,
        budget_id: props.filters?.budget_id
    }, {
        preserveState: true,
        preserveScroll: true
    });
};

const navigateToToday = () => {
    const today = new Date();
    router.get(route('calendar.index'), {
        year: today.getFullYear(),
        month: today.getMonth() + 1,
        budget_id: props.filters?.budget_id
    }, {
        preserveState: true,
        preserveScroll: true
    });
};

const handleDayClick = (day) => {
    selectedDay.value = day;
    showDayModal.value = true;
};

const closeDayModal = () => {
    showDayModal.value = false;
    selectedDay.value = null;
};

const changeBudget = (budgetId) => {
    router.get(route('calendar.index'), {
        budget_id: budgetId,
        year: currentDate.value.year,
        month: currentDate.value.month
    });
};
</script>

<template>
    <Head title="Calendar" />

    <AuthenticatedLayout>
        <div class="py-6">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <!-- Header -->
                <div class="mb-6">
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
                        Transaction Calendar
                    </h1>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        View your posted and projected transactions in a calendar view
                    </p>
                </div>

                <!-- Budget selector (if user has multiple budgets) -->
                <div v-if="budgets.length > 1" class="mb-4">
                    <label for="budget-select" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Select Budget
                    </label>
                    <select
                        id="budget-select"
                        :value="selectedBudget?.id"
                        @change="changeBudget($event.target.value)"
                        class="block w-full sm:w-64 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                    >
                        <option v-for="budget in budgets" :key="budget.id" :value="budget.id">
                            {{ budget.name }}
                        </option>
                    </select>
                </div>

                <!-- Empty state (no budget selected) -->
                <div v-if="!selectedBudget" class="bg-white dark:bg-gray-800 rounded-lg shadow p-12 text-center">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-white">No budget selected</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Create a budget to start tracking your transactions in the calendar.
                    </p>
                    <div class="mt-6">
                        <Link
                            :href="route('budgets.create')"
                            class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700"
                        >
                            Create Budget
                        </Link>
                    </div>
                </div>

                <!-- Calendar view -->
                <div v-else>
                    <!-- Calendar controls -->
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center space-x-2">
                            <button
                                @click="navigateToMonth(-1)"
                                class="p-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300"
                            >
                                <ChevronLeftIcon class="w-5 h-5" />
                            </button>
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white min-w-[200px] text-center">
                                {{ calendarData.monthName }}
                            </h2>
                            <button
                                @click="navigateToMonth(1)"
                                class="p-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300"
                            >
                                <ChevronRightIcon class="w-5 h-5" />
                            </button>
                        </div>
                        <button
                            @click="navigateToToday"
                            class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md hover:bg-gray-50 dark:hover:bg-gray-600"
                        >
                            Today
                        </button>
                    </div>

                    <!-- Legend -->
                    <div class="mb-4 flex flex-wrap items-center gap-4 text-sm text-gray-600 dark:text-gray-400">
                        <div class="flex items-center">
                            <div class="w-3 h-3 rounded-full bg-green-500 mr-2"></div>
                            <span>Posted Income</span>
                        </div>
                        <div class="flex items-center">
                            <div class="w-3 h-3 rounded-full bg-red-500 mr-2"></div>
                            <span>Posted Expense</span>
                        </div>
                        <div class="flex items-center">
                            <div class="w-3 h-3 rounded-full border-2 border-green-500 mr-2"></div>
                            <span>Projected Income</span>
                        </div>
                        <div class="flex items-center">
                            <div class="w-3 h-3 rounded-full border-2 border-red-500 mr-2"></div>
                            <span>Projected Expense</span>
                        </div>
                    </div>

                    <!-- Calendar grid -->
                    <CalendarGrid
                        :calendar-data="calendarData"
                        @day-click="handleDayClick"
                    />
                </div>

                <!-- Day detail modal -->
                <div
                    v-if="showDayModal && selectedDay"
                    class="fixed inset-0 z-50 overflow-y-auto"
                    @click.self="closeDayModal"
                >
                    <div class="flex min-h-screen items-center justify-center p-4">
                        <!-- Backdrop -->
                        <div class="fixed inset-0 bg-gray-600 bg-opacity-75 transition-opacity" @click="closeDayModal"></div>

                        <!-- Modal panel -->
                        <div class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-2xl w-full max-h-[80vh] overflow-hidden">
                            <!-- Header -->
                            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                                <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                                    {{ new Date(selectedDay.date).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' }) }}
                                </h3>
                                <button
                                    @click="closeDayModal"
                                    class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300"
                                >
                                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            <!-- Body -->
                            <div class="px-6 py-4 overflow-y-auto max-h-[60vh]">
                                <!-- Posted transactions -->
                                <div v-if="selectedDay.transactions.posted.length > 0" class="mb-6">
                                    <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Posted Transactions</h4>
                                    <div class="space-y-2">
                                        <div
                                            v-for="transaction in selectedDay.transactions.posted"
                                            :key="transaction.id"
                                            class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-md"
                                        >
                                            <div class="flex-1">
                                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ transaction.description }}</p>
                                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ transaction.category }} • {{ transaction.account_name }}</p>
                                            </div>
                                            <span
                                                :class="[
                                                    'text-sm font-semibold',
                                                    transaction.amount_in_cents >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'
                                                ]"
                                            >
                                                {{ formatCurrency(transaction.amount_in_cents) }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Projected transactions -->
                                <div v-if="selectedDay.transactions.projected.length > 0" class="mb-6">
                                    <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Projected Transactions</h4>
                                    <div class="space-y-2">
                                        <div
                                            v-for="(transaction, index) in selectedDay.transactions.projected"
                                            :key="`projected-${index}`"
                                            class="flex items-center justify-between p-3 bg-blue-50 dark:bg-blue-900/20 rounded-md border border-blue-200 dark:border-blue-800"
                                        >
                                            <div class="flex-1">
                                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ transaction.description }}</p>
                                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                                    {{ transaction.category }} • {{ transaction.frequency }}
                                                    <span v-if="transaction.is_variable" class="italic">(Variable)</span>
                                                </p>
                                            </div>
                                            <span
                                                :class="[
                                                    'text-sm font-semibold',
                                                    transaction.expected_amount_in_cents >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'
                                                ]"
                                            >
                                                {{ formatCurrency(transaction.expected_amount_in_cents) }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Empty state -->
                                <div v-if="selectedDay.transactions.posted.length === 0 && selectedDay.transactions.projected.length === 0" class="text-center py-8">
                                    <p class="text-gray-500 dark:text-gray-400">No transactions on this day</p>
                                </div>

                                <!-- Summary -->
                                <div class="mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
                                    <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Day Summary</h4>
                                    <div class="grid grid-cols-2 gap-4 text-sm">
                                        <div>
                                            <p class="text-gray-500 dark:text-gray-400">Total Income</p>
                                            <p class="font-semibold text-green-600 dark:text-green-400">
                                                {{ formatCurrency(selectedDay.totals.posted_income_cents + selectedDay.totals.projected_income_cents) }}
                                            </p>
                                        </div>
                                        <div>
                                            <p class="text-gray-500 dark:text-gray-400">Total Expenses</p>
                                            <p class="font-semibold text-red-600 dark:text-red-400">
                                                {{ formatCurrency(selectedDay.totals.posted_expenses_cents + selectedDay.totals.projected_expenses_cents) }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
