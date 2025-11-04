<script setup>
import { ref, watch } from 'vue';
import { CalendarIcon } from '@heroicons/vue/24/outline';

const props = defineProps({
    startDate: String,
    endDate: String,
    preset: {
        type: String,
        default: '6months'
    }
});

const emit = defineEmits(['update']);

const presets = [
    { label: 'Last 30 Days', value: '30days' },
    { label: 'Last 3 Months', value: '3months' },
    { label: 'Last 6 Months', value: '6months' },
    { label: 'Year to Date', value: 'ytd' },
    { label: 'Last Year', value: 'year' },
    { label: 'All Time', value: 'all' },
    { label: 'Custom', value: 'custom' },
];

const selectedPreset = ref(props.preset);
const customStartDate = ref(props.startDate);
const customEndDate = ref(props.endDate);
const showDropdown = ref(false);
const showCustom = ref(props.preset === 'custom');

const currentPresetLabel = ref(
    presets.find(p => p.value === props.preset)?.label || 'Last 6 Months'
);

watch(selectedPreset, (newPreset) => {
    if (newPreset !== 'custom') {
        showCustom.value = false;
        currentPresetLabel.value = presets.find(p => p.value === newPreset)?.label || '';
        emit('update', { date_range: newPreset });
        showDropdown.value = false;
    } else {
        showCustom.value = true;
    }
});

const applyCustomRange = () => {
    if (customStartDate.value && customEndDate.value) {
        currentPresetLabel.value = `${customStartDate.value} to ${customEndDate.value}`;
        emit('update', {
            date_range: 'custom',
            start_date: customStartDate.value,
            end_date: customEndDate.value
        });
        showDropdown.value = false;
    }
};

const toggleDropdown = () => {
    showDropdown.value = !showDropdown.value;
};

// Close dropdown when clicking outside
const closeDropdown = (event) => {
    if (!event.target.closest('.date-range-picker')) {
        showDropdown.value = false;
    }
};

// Add click listener when component mounts
import { onMounted, onUnmounted } from 'vue';
onMounted(() => {
    document.addEventListener('click', closeDropdown);
});
onUnmounted(() => {
    document.removeEventListener('click', closeDropdown);
});
</script>

<template>
    <div class="relative date-range-picker">
        <button
            @click="toggleDropdown"
            type="button"
            class="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
        >
            <CalendarIcon class="w-5 h-5 mr-2" />
            {{ currentPresetLabel }}
            <svg class="ml-2 w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </button>

        <!-- Dropdown -->
        <div
            v-if="showDropdown"
            class="absolute right-0 z-50 mt-2 w-72 bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-md shadow-lg"
        >
            <div class="p-4">
                <!-- Preset Options -->
                <div class="space-y-2 mb-4">
                    <label
                        v-for="preset in presets"
                        :key="preset.value"
                        class="flex items-center p-2 rounded hover:bg-gray-50 dark:hover:bg-gray-600 cursor-pointer"
                    >
                        <input
                            type="radio"
                            :value="preset.value"
                            v-model="selectedPreset"
                            class="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500"
                        />
                        <span class="ml-3 text-sm text-gray-700 dark:text-gray-300">
                            {{ preset.label }}
                        </span>
                    </label>
                </div>

                <!-- Custom Date Inputs -->
                <div v-if="showCustom" class="border-t border-gray-200 dark:border-gray-600 pt-4 space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Start Date
                        </label>
                        <input
                            type="date"
                            v-model="customStartDate"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-600 dark:text-white text-sm"
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            End Date
                        </label>
                        <input
                            type="date"
                            v-model="customEndDate"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-600 dark:text-white text-sm"
                        />
                    </div>
                    <button
                        @click="applyCustomRange"
                        type="button"
                        class="w-full px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                    >
                        Apply Custom Range
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
