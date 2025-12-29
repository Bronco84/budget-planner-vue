<template>
  <div class="fixed inset-0 z-50 overflow-y-auto" v-if="show">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
      <!-- Background overlay -->
      <div class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75" @click="$emit('close')"></div>

      <!-- Modal panel -->
      <div class="inline-block w-full max-w-4xl my-8 overflow-hidden text-left align-middle transition-all transform bg-white shadow-xl rounded-lg">
        <form @submit.prevent="handleSubmit">
          <!-- Header -->
          <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex items-center justify-between">
              <h3 class="text-lg font-medium text-gray-900">
                {{ isEditing ? 'Edit Scenario' : 'Create New Scenario' }}
              </h3>
              <button
                type="button"
                @click="$emit('close')"
                class="text-gray-400 hover:text-gray-500"
              >
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
          </div>

          <!-- Body -->
          <div class="px-6 py-4 max-h-[calc(100vh-200px)] overflow-y-auto">
            <div class="space-y-6">
              <!-- Basic Info -->
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                  <label class="block text-sm font-medium text-gray-700 mb-1">
                    Scenario Name <span class="text-red-500">*</span>
                  </label>
                  <input
                    v-model="form.name"
                    type="text"
                    required
                    class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                    placeholder="e.g., Buy Car - $40k"
                  />
                </div>

                <div class="md:col-span-2">
                  <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                  <textarea
                    v-model="form.description"
                    rows="2"
                    class="block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                    placeholder="Optional description of this scenario"
                  ></textarea>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">
                    Color <span class="text-red-500">*</span>
                  </label>
                  <div class="flex items-center space-x-2">
                    <input
                      v-model="form.color"
                      type="color"
                      class="h-10 w-20 border-gray-300 rounded-md cursor-pointer"
                    />
                    <div class="flex flex-wrap gap-2">
                      <button
                        v-for="color in colorPalette"
                        :key="color"
                        type="button"
                        @click="form.color = color"
                        class="w-8 h-8 rounded-md border-2 hover:scale-110 transition-transform"
                        :class="form.color === color ? 'border-gray-900' : 'border-gray-300'"
                        :style="{ backgroundColor: color }"
                        :title="color"
                      ></button>
                    </div>
                  </div>
                </div>

                <div class="flex items-center">
                  <input
                    v-model="form.is_active"
                    type="checkbox"
                    id="is_active"
                    class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
                  />
                  <label for="is_active" class="ml-2 block text-sm text-gray-900">
                    Active (show in projections)
                  </label>
                </div>
              </div>

              <!-- Adjustments -->
              <div>
                <div class="flex justify-between items-center mb-4">
                  <label class="block text-sm font-medium text-gray-700">
                    Adjustments <span class="text-red-500">*</span>
                  </label>
                  <button
                    type="button"
                    @click="addAdjustment"
                    class="inline-flex items-center px-3 py-1.5 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                  >
                    <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Add Adjustment
                  </button>
                </div>

                <div v-if="form.adjustments.length === 0" class="text-center py-8 text-gray-500 border-2 border-dashed border-gray-300 rounded-lg">
                  <p>No adjustments yet</p>
                  <p class="text-sm mt-1">Add at least one adjustment to create a scenario</p>
                </div>

                <div v-else class="space-y-4">
                  <ScenarioAdjustmentRow
                    v-for="(adjustment, index) in form.adjustments"
                    :key="index"
                    v-model="form.adjustments[index]"
                    :index="index"
                    :accounts="accounts"
                    @remove="removeAdjustment(index)"
                  />
                </div>
              </div>

              <!-- Error message -->
              <div v-if="error" class="rounded-md bg-red-50 p-4">
                <div class="flex">
                  <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                  </div>
                  <div class="ml-3">
                    <p class="text-sm text-red-800">{{ error }}</p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Footer -->
          <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 flex justify-end space-x-3">
            <button
              type="button"
              @click="$emit('close')"
              class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
            >
              Cancel
            </button>
            <button
              type="submit"
              :disabled="processing || form.adjustments.length === 0"
              class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {{ processing ? 'Saving...' : (isEditing ? 'Update Scenario' : 'Create Scenario') }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import ScenarioAdjustmentRow from './ScenarioAdjustmentRow.vue';

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  scenario: {
    type: Object,
    default: null,
  },
  accounts: {
    type: Array,
    default: () => [],
  },
});

const emit = defineEmits(['close', 'save']);

const colorPalette = [
  '#3b82f6', // blue
  '#10b981', // green
  '#f59e0b', // amber
  '#ef4444', // red
  '#8b5cf6', // purple
  '#ec4899', // pink
  '#14b8a6', // teal
  '#f97316', // orange
];

const form = ref({
  name: '',
  description: '',
  color: '#3b82f6',
  is_active: true,
  adjustments: [],
});

const processing = ref(false);
const error = ref('');

const isEditing = computed(() => !!props.scenario);

const resetForm = () => {
  form.value = {
    name: '',
    description: '',
    color: '#3b82f6',
    is_active: true,
    adjustments: [],
  };
};

// Watch for scenario changes to populate form
watch(() => props.scenario, (newScenario) => {
  if (newScenario) {
    form.value = {
      name: newScenario.name,
      description: newScenario.description || '',
      color: newScenario.color || '#3b82f6',
      is_active: newScenario.is_active ?? true,
      adjustments: newScenario.adjustments?.map(adj => ({
        id: adj.id,
        adjustment_type: adj.adjustment_type,
        account_id: adj.account_id,
        amount_in_cents: adj.amount_in_cents,
        start_date: adj.start_date,
        end_date: adj.end_date || '',
        frequency: adj.frequency || '',
        day_of_week: adj.day_of_week ?? '',
        day_of_month: adj.day_of_month ?? '',
        description: adj.description || '',
      })) || [],
    };
  } else {
    resetForm();
  }
}, { immediate: true });

// Watch for show prop to reset form when modal closes
watch(() => props.show, (newShow) => {
  if (!newShow && !props.scenario) {
    resetForm();
  }
  error.value = '';
});

const addAdjustment = () => {
  form.value.adjustments.push({
    adjustment_type: '',
    account_id: '',
    amount_in_cents: 0,
    start_date: new Date().toISOString().split('T')[0],
    end_date: '',
    frequency: '',
    day_of_week: '',
    day_of_month: '',
    description: '',
  });
};

const removeAdjustment = (index) => {
  form.value.adjustments.splice(index, 1);
};

const handleSubmit = async () => {
  error.value = '';
  
  // Validation
  if (!form.value.name.trim()) {
    error.value = 'Please enter a scenario name';
    return;
  }
  
  if (form.value.adjustments.length === 0) {
    error.value = 'Please add at least one adjustment';
    return;
  }
  
  // Validate each adjustment
  for (let i = 0; i < form.value.adjustments.length; i++) {
    const adj = form.value.adjustments[i];
    if (!adj.adjustment_type) {
      error.value = `Adjustment ${i + 1}: Please select a type`;
      return;
    }
    if (!adj.account_id) {
      error.value = `Adjustment ${i + 1}: Please select an account`;
      return;
    }
    if (!adj.start_date) {
      error.value = `Adjustment ${i + 1}: Please enter a start date`;
      return;
    }
    if (adj.adjustment_type !== 'one_time_expense' && !adj.frequency) {
      error.value = `Adjustment ${i + 1}: Please select a frequency`;
      return;
    }
  }
  
  processing.value = true;
  
  try {
    emit('save', form.value);
  } catch (e) {
    error.value = e.message || 'An error occurred while saving the scenario';
  } finally {
    processing.value = false;
  }
};
</script>

