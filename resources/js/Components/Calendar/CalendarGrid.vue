<script setup>
import DayCell from './DayCell.vue';

const props = defineProps({
    calendarData: {
        type: Object,
        required: true
    }
});

const emit = defineEmits(['dayClick']);

const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

// Calculate empty cells for days before the start of the month
const getEmptyCellsBefore = () => {
    return Array(props.calendarData.startDayOfWeek).fill(null);
};

// Calculate empty cells for days after the end of the month
const getEmptyCellsAfter = () => {
    const totalCells = props.calendarData.days.length + props.calendarData.startDayOfWeek;
    const remainingCells = 7 - (totalCells % 7);
    return remainingCells === 7 ? [] : Array(remainingCells).fill(null);
};
</script>

<template>
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
        <!-- Weekday headers -->
        <div class="grid grid-cols-7 gap-0 border-b border-gray-200 dark:border-gray-700">
            <div
                v-for="day in weekdays"
                :key="day"
                class="py-2 text-center text-sm font-semibold text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-900"
            >
                {{ day }}
            </div>
        </div>

        <!-- Calendar grid -->
        <div class="grid grid-cols-7 gap-0">
            <!-- Empty cells before month starts -->
            <div
                v-for="(_, index) in getEmptyCellsBefore()"
                :key="`empty-before-${index}`"
                class="min-h-24 bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700"
            ></div>

            <!-- Actual days of the month -->
            <DayCell
                v-for="day in calendarData.days"
                :key="day.date"
                :day="day"
                :is-current-month="true"
                @click="emit('dayClick', day)"
            />

            <!-- Empty cells after month ends -->
            <div
                v-for="(_, index) in getEmptyCellsAfter()"
                :key="`empty-after-${index}`"
                class="min-h-24 bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700"
            ></div>
        </div>
    </div>
</template>
