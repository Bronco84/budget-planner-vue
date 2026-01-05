<template>
  <div class="bg-white rounded-lg shadow-sm border border-gray-200">
    <div class="p-4 border-b border-gray-200">
      <div class="flex justify-between items-center">
        <h3 class="text-lg font-medium text-gray-900">Scenarios</h3>
        <button
          @click="$emit('create')"
          class="inline-flex items-center px-3 py-1.5 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
        >
          <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Create New
        </button>
      </div>
    </div>

    <div class="p-4">
      <div v-if="scenarios.length === 0" class="text-center py-8 text-gray-500">
        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
        </svg>
        <p class="mt-2">No scenarios yet</p>
        <p class="text-sm">Create a scenario to model different financial decisions</p>
      </div>

      <div v-else class="space-y-3">
        <div
          v-for="scenario in scenarios"
          :key="scenario.id"
          class="flex items-start p-3 rounded-lg border border-gray-200 hover:border-gray-300 transition-colors"
          :class="{ 'bg-gray-50': scenario.is_active }"
        >
          <div class="flex items-center h-5">
            <input
              :id="`scenario-${scenario.id}`"
              type="checkbox"
              :checked="scenario.is_active"
              @change="$emit('toggle', scenario.id)"
              class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
            />
          </div>
          
          <div class="ml-3 flex-1 min-w-0">
            <label :for="`scenario-${scenario.id}`" class="flex items-center cursor-pointer">
              <div
                class="w-3 h-3 rounded-full mr-2 flex-shrink-0"
                :style="{ backgroundColor: scenario.color }"
              ></div>
              <span class="text-sm font-medium text-gray-900">{{ scenario.name }}</span>
            </label>
            
            <p v-if="scenario.description" class="mt-1 text-xs text-gray-500">
              {{ scenario.description }}
            </p>
            
            <div v-if="scenario.adjustments && scenario.adjustments.length > 0" class="mt-2 flex flex-wrap gap-1">
              <span
                v-for="(account, index) in getAffectedAccounts(scenario)"
                :key="index"
                class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800"
              >
                {{ account }}
              </span>
            </div>
          </div>

          <div class="ml-3 flex items-center space-x-2">
            <button
              @click="$emit('edit', scenario)"
              class="text-gray-400 hover:text-gray-600"
              title="Edit scenario"
            >
              <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
              </svg>
            </button>
            <button
              @click="$emit('delete', scenario.id)"
              class="text-gray-400 hover:text-red-600"
              title="Delete scenario"
            >
              <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
              </svg>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  scenarios: {
    type: Array,
    default: () => [],
  },
});

defineEmits(['create', 'edit', 'delete', 'toggle']);

const getAffectedAccounts = (scenario) => {
  if (!scenario.adjustments || scenario.adjustments.length === 0) {
    return [];
  }
  
  const accountNames = new Set();
  scenario.adjustments.forEach(adjustment => {
    if (adjustment.account && adjustment.account.name) {
      accountNames.add(adjustment.account.name);
    }
  });
  
  return Array.from(accountNames);
};
</script>



