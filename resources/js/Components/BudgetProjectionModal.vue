<template>
  <Modal :show="show" @close="$emit('close')" max-width="md">
    <div class="p-6">
      <h2 class="text-lg font-medium text-gray-900 mb-4">
        Budget Time Projection
      </h2>

      <div class="space-y-4">
        <div>
          <label for="projection_months" class="block text-sm font-medium text-gray-700 mb-2">
            Project Future Transactions
          </label>
          <select
            id="projection_months"
            v-model="localMonths"
            @change="handleUpdate"
            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
          >
            <option value="0">No projections</option>
            <option value="1">1 month</option>
            <option value="2">2 months</option>
            <option value="3">3 months</option>
            <option value="6">6 months</option>
            <option value="12">12 months</option>
          </select>
        </div>

        <div v-if="localMonths > 0 && projectedCount > 0" class="mt-3 p-3 bg-blue-50 rounded-md">
          <div class="flex items-center">
            <svg class="h-5 w-5 text-blue-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span class="text-sm text-blue-700">
              Showing {{ projectedCount }} projected transaction{{ projectedCount === 1 ? '' : 's' }}
            </span>
          </div>
        </div>
      </div>

      <div class="mt-6 flex justify-end space-x-3">
        <button
          @click="$emit('close')"
          type="button"
          class="inline-flex justify-center px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
        >
          Close
        </button>
      </div>
    </div>
  </Modal>
</template>

<script setup>
import { ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
  show: Boolean,
  months: Number,
  projectedCount: {
    type: Number,
    default: 0
  }
});

const emit = defineEmits(['close', 'update']);

const localMonths = ref(props.months || 0);

watch(() => props.months, (newValue) => {
  localMonths.value = newValue || 0;
});

watch(() => props.show, (newValue) => {
  if (newValue) {
    localMonths.value = props.months || 0;
  }
});

const handleUpdate = () => {
  emit('update', parseInt(localMonths.value));
};
</script>

