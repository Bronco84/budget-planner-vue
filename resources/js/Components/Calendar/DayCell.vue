<script setup>
import { computed } from 'vue';
import { formatCurrency } from '@/utils/format.js';

const props = defineProps({
    day: {
        type: Object,
        required: true
    },
    isCurrentMonth: {
        type: Boolean,
        default: true
    }
});

const emit = defineEmits(['click']);

const hasTransactions = computed(() => {
    return props.day.counts.posted > 0 || props.day.counts.projected > 0;
});

const netAmount = computed(() => {
    const postedNet = props.day.totals.posted_income_cents - props.day.totals.posted_expenses_cents;
    const projectedNet = props.day.totals.projected_income_cents - props.day.totals.projected_expenses_cents;
    return postedNet + projectedNet;
});

const isPositive = computed(() => netAmount.value >= 0);
</script>

<template>
    <div
        @click="emit('click', day)"
        :class="[
            'min-h-24 border p-2 cursor-pointer transition-colors',
            {
                'bg-white dark:bg-gray-800': isCurrentMonth,
                'bg-gray-50 dark:bg-gray-900': !isCurrentMonth,
                'bg-blue-50 dark:bg-blue-900/20': day.isToday,
                'hover:bg-gray-50 dark:hover:bg-gray-700': isCurrentMonth && !day.isToday,
                'border-gray-200 dark:border-gray-700': !day.isToday,
                'border-2 border-blue-500': day.isToday
            }
        ]"
    >
        <!-- Day number -->
        <div class="flex items-center justify-between mb-1">
            <span
                :class="[
                    'text-sm font-medium',
                    {
                        'text-blue-600 dark:text-blue-400': day.isToday,
                        'text-gray-900 dark:text-gray-100': !day.isToday && isCurrentMonth,
                        'text-gray-400 dark:text-gray-600': !isCurrentMonth
                    }
                ]"
            >
                {{ day.day }}
            </span>
            <span
                v-if="day.isWeekend"
                class="text-xs text-gray-400 dark:text-gray-600"
            >
                •
            </span>
        </div>

        <!-- Transaction indicators -->
        <div v-if="hasTransactions" class="space-y-1">
            <!-- Posted transactions indicator -->
            <div v-if="day.counts.posted > 0" class="flex items-center text-xs">
                <div
                    :class="[
                        'w-2 h-2 rounded-full mr-1.5',
                        day.totals.posted_income_cents > day.totals.posted_expenses_cents
                            ? 'bg-green-500'
                            : 'bg-red-500'
                    ]"
                ></div>
                <span class="text-gray-600 dark:text-gray-400 truncate">
                    {{ day.counts.posted }}
                </span>
            </div>

            <!-- Projected transactions indicator -->
            <div v-if="day.counts.projected > 0" class="flex items-center text-xs">
                <div
                    :class="[
                        'w-2 h-2 rounded-full border-2 mr-1.5',
                        day.totals.projected_income_cents > day.totals.projected_expenses_cents
                            ? 'border-green-500'
                            : 'border-red-500'
                    ]"
                ></div>
                <span class="text-gray-500 dark:text-gray-500 truncate italic">
                    {{ day.counts.projected }}
                </span>
            </div>

            <!-- Net amount (if there are transactions) -->
            <div v-if="netAmount !== 0" class="text-xs font-medium mt-1 truncate" :class="isPositive ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'">
                {{ formatCurrency(netAmount) }}
            </div>
        </div>

        <!-- Empty state -->
        <div v-else class="text-xs text-gray-300 dark:text-gray-700 text-center mt-2">
            —
        </div>
    </div>
</template>
