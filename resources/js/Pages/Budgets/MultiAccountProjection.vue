<template>
  <Head title="Scenarios" />

  <AuthenticatedLayout>
    <div class="min-h-screen flex flex-col bg-gray-50 dark:bg-gray-900">
      <!-- Page Header -->
      <div class="flex-shrink-0 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 px-4 sm:px-6 lg:px-8 py-6">
        <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100">Scenarios & Projections</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Compare different financial scenarios across your accounts</p>
      </div>

      <!-- Main Content Area -->
      <div class="flex-1 flex gap-6 px-4 sm:px-6 lg:px-8 py-6">
          <!-- Left Sidebar - Controls -->
          <div class="w-80 flex-shrink-0 space-y-4">
            <!-- Account Selector -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
              <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center justify-between">
                  <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 uppercase tracking-wider">Accounts</h3>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ selectedAccountIds.length }} selected</p>
                  </div>
                  <div class="flex gap-2">
                    <button
                      @click="selectAllAccounts"
                      class="text-xs text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300 font-medium"
                    >
                      All
                    </button>
                    <span class="text-xs text-gray-300 dark:text-gray-600">|</span>
                    <button
                      @click="deselectAllAccounts"
                      class="text-xs text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300 font-medium"
                    >
                      None
                    </button>
                  </div>
                </div>
              </div>
              <div class="p-3">
                <div class="space-y-1 max-h-64 overflow-y-auto">
                  <label
                    v-for="account in budget.accounts"
                    :key="account.id"
                    class="flex items-center cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 p-2 rounded transition-colors"
                  >
                    <input
                      type="checkbox"
                      :value="account.id"
                      v-model="selectedAccountIds"
                      @change="handleAccountChange"
                      class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
                    />
                    <div class="ml-3 flex-1 min-w-0">
                      <span class="text-sm text-gray-900 dark:text-gray-100 block truncate">{{ account.name }}</span>
                      <span class="text-xs text-gray-500 dark:text-gray-400">{{ formatCurrency(account.current_balance_cents) }}</span>
                    </div>
                  </label>
                </div>
              </div>
            </div>

            <!-- Timeframe Selector -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
              <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 uppercase tracking-wider">Timeframe</h3>
              </div>
              <div class="p-4">
                <select
                  v-model="projectionMonths"
                  @change="handleTimeframeChange"
                  class="block w-full border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm"
                >
                  <option :value="1">1 month</option>
                  <option :value="3">3 months</option>
                  <option :value="6">6 months</option>
                  <option :value="12">12 months</option>
                  <option :value="24">24 months (2 years)</option>
                  <option :value="36">36 months (3 years)</option>
                  <option :value="60">60 months (5 years)</option>
                </select>

                <button
                  @click="refreshProjections"
                  :disabled="selectedAccountIds.length === 0 || refreshing"
                  class="mt-3 w-full inline-flex justify-center items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                  <svg v-if="refreshing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                  <svg v-else class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                  </svg>
                  {{ refreshing ? 'Updating...' : 'Update Projections' }}
                </button>
              </div>
            </div>

            <!-- Scenarios -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
              <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 uppercase tracking-wider">Scenarios</h3>
              </div>
              <div class="p-3">
                <div v-if="scenarios.length === 0" class="text-center py-8 text-gray-500 dark:text-gray-400 text-sm">
                  <svg class="mx-auto h-12 w-12 text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                  </svg>
                  <p class="font-medium">No scenarios yet</p>
                  <p class="text-xs mt-1">Create one to compare projections</p>
                </div>

                <div v-else class="space-y-1 max-h-64 overflow-y-auto">
                  <div
                    v-for="scenario in scenarios"
                    :key="scenario.id"
                    class="flex items-center justify-between p-2 rounded hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                  >
                    <div class="flex items-center min-w-0 flex-1">
                      <input
                        :id="`scenario-${scenario.id}`"
                        type="checkbox"
                        :checked="scenario.is_active"
                        @change="handleToggleScenario(scenario.id)"
                        class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded flex-shrink-0"
                      />
                      <label :for="`scenario-${scenario.id}`" class="ml-3 flex items-center cursor-pointer min-w-0 flex-1">
                        <div
                          class="w-3 h-3 rounded-full mr-2 flex-shrink-0"
                          :style="{ backgroundColor: scenario.color }"
                        ></div>
                        <span class="text-sm text-gray-900 dark:text-gray-100 truncate">{{ scenario.name }}</span>
                      </label>
                    </div>
                    <div class="flex items-center space-x-1 ml-2 flex-shrink-0">
                      <button
                        @click="handleEditScenario(scenario)"
                        class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 p-1 rounded transition-colors"
                        title="Edit"
                      >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                      </button>
                      <button
                        @click="handleDeleteScenario(scenario.id)"
                        class="text-gray-400 hover:text-red-600 dark:hover:text-red-400 p-1 rounded transition-colors"
                        title="Delete"
                      >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                      </button>
                    </div>
                  </div>
                </div>

                <button
                  @click="showScenarioForm = true; editingScenario = null"
                  class="mt-3 w-full inline-flex justify-center items-center px-3 py-2 border border-gray-300 dark:border-gray-600 text-sm font-medium rounded-md text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors"
                >
                  <svg class="h-4 w-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                  </svg>
                  Create Scenario
                </button>
              </div>
            </div>

            <!-- Quick Stats -->
            <div v-if="selectedAccountIds.length > 0" class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
              <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 uppercase tracking-wider">Summary</h3>
              </div>
              <div class="p-4 space-y-3">
                <div>
                  <p class="text-xs text-gray-500 dark:text-gray-400">Total Current Balance</p>
                  <p class="text-lg font-bold text-gray-900 dark:text-gray-100">
                    {{ formatCurrency(totalCurrentBalance) }}
                  </p>
                </div>
                
                <template v-if="activeScenarios.length === 0">
                  <!-- No scenarios active - show base projection -->
                  <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Projected Balance</p>
                    <p class="text-lg font-bold" :class="totalProjectedBalance >= 0 ? 'text-green-600' : 'text-red-600'">
                      {{ formatCurrency(totalProjectedBalance) }}
                    </p>
                  </div>
                  <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Expected Change</p>
                    <p class="text-lg font-bold" :class="totalChange >= 0 ? 'text-green-600' : 'text-red-600'">
                      {{ formatCurrency(totalChange) }}
                    </p>
                  </div>
                </template>
                
                <template v-else>
                  <!-- Scenarios active - show comparison -->
                  <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Base Projection</p>
                    <p class="text-base font-semibold text-gray-700 dark:text-gray-300">
                      {{ formatCurrency(totalProjectedBalance) }}
                    </p>
                  </div>
                  <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">With Scenarios</p>
                    <p class="text-base font-semibold text-gray-700 dark:text-gray-300">
                      {{ formatCurrency(totalScenarioBalance) }}
                    </p>
                  </div>
                  <div class="pt-2 border-t border-gray-200 dark:border-gray-600">
                    <p class="text-xs text-gray-500 dark:text-gray-400">Net Impact</p>
                    <p class="text-xl font-bold" :class="totalScenarioImpact >= 0 ? 'text-green-600' : 'text-red-600'">
                      <span v-if="totalScenarioImpact >= 0">+</span>{{ formatCurrency(totalScenarioImpact) }}
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                      {{ ((totalScenarioImpact / totalProjectedBalance) * 100).toFixed(1) }}% change
                    </p>
                  </div>
                </template>
              </div>
            </div>
          </div>

          <!-- Right Content - Chart -->
          <div class="flex-1 bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
              <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Balance Projections</h3>
              <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ projectionMonths }} month{{ projectionMonths !== 1 ? 's' : '' }} projection
                <span v-if="activeScenarios.length > 0">
                  • {{ activeScenarios.length }} scenario{{ activeScenarios.length !== 1 ? 's' : '' }} active
                </span>
              </p>
            </div>
            
            <div class="p-6">
              <div v-if="selectedAccountIds.length === 0" class="flex items-center justify-center text-gray-500 dark:text-gray-400" style="min-height: 600px;">
                <div class="text-center">
                  <svg class="mx-auto h-16 w-16 text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                  </svg>
                  <p class="text-lg font-medium">Select accounts to view projections</p>
                  <p class="mt-2 text-sm">Choose one or more accounts from the sidebar to get started</p>
                </div>
              </div>

              <div v-else>
                <MultiAccountBalanceChart
                  :accounts="displayedAccounts"
                  :baseProjections="baseProjections"
                  :scenarioProjections="scenarioProjections"
                  :scenarios="activeScenarios"
                  :height="800"
                />
              </div>
            </div>
          </div>
        </div>
      </div>

    <!-- Scenario Form Modal -->
    <ScenarioForm
      :show="showScenarioForm"
      :scenario="editingScenario"
      :accounts="budget.accounts"
      @close="showScenarioForm = false; editingScenario = null"
      @save="handleSaveScenario"
    />
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ScenarioManager from '@/Components/ScenarioManager.vue';
import ScenarioForm from '@/Components/ScenarioForm.vue';
import MultiAccountBalanceChart from '@/Components/MultiAccountBalanceChart.vue';
import { formatCurrency } from '@/utils/format.js';
import { useScenarios } from '@/composables/useScenarios.js';
import { useToast } from '@/composables/useToast';

const props = defineProps({
  budget: Object,
  accounts: Array,
  baseProjections: Object,
  scenarioProjections: Object,
  scenarios: Array,
  activeScenarios: Array,
  monthsAhead: Number,
});

const { createScenario, updateScenario, deleteScenario, toggleScenario } = useScenarios(props.budget.id);

// Initialize toast
const toast = useToast();

// Local state
const selectedAccountIds = ref([]);
const projectionMonths = ref(props.monthsAhead || 12);
const showScenarioForm = ref(false);
const editingScenario = ref(null);
const refreshing = ref(false);

// Initialize selected accounts from props
onMounted(() => {
  if (props.accounts && props.accounts.length > 0) {
    selectedAccountIds.value = props.accounts.map(a => a.id);
  }
});

// Computed properties
const displayedAccounts = computed(() => {
  return props.budget.accounts.filter(account => 
    selectedAccountIds.value.includes(account.id)
  );
});

const activeScenarios = computed(() => {
  return props.scenarios.filter(s => s.is_active);
});

const totalCurrentBalance = computed(() => {
  return displayedAccounts.value.reduce((sum, account) => sum + (account.current_balance_cents || 0), 0);
});

const totalProjectedBalance = computed(() => {
  return displayedAccounts.value.reduce((sum, account) => {
    return sum + getEndingBalance(account.id, null);
  }, 0);
});

const totalChange = computed(() => {
  return totalProjectedBalance.value - totalCurrentBalance.value;
});

const totalScenarioBalance = computed(() => {
  if (activeScenarios.value.length === 0) return totalProjectedBalance.value;
  
  // Get the first active scenario's projection
  const firstScenarioId = activeScenarios.value[0].id;
  return displayedAccounts.value.reduce((sum, account) => {
    return sum + getEndingBalance(account.id, firstScenarioId);
  }, 0);
});

const totalScenarioImpact = computed(() => {
  return totalScenarioBalance.value - totalProjectedBalance.value;
});

// Helper functions
const getEndingBalance = (accountId, scenarioId) => {
  let projection;
  
  if (scenarioId === null) {
    // Base projection
    projection = props.baseProjections[accountId];
  } else {
    // Scenario projection
    projection = props.scenarioProjections[scenarioId]?.[accountId];
  }
  
  if (!projection || !projection.days || projection.days.length === 0) {
    return 0;
  }
  
  return projection.days[projection.days.length - 1].balance;
};

const getBalanceChange = (accountId, scenarioId) => {
  const account = props.budget.accounts.find(a => a.id === accountId);
  if (!account) return 0;
  
  const endingBalance = getEndingBalance(accountId, scenarioId);
  return endingBalance - account.current_balance_cents;
};

const getScenarioDifference = (accountId, scenarioId) => {
  const baseEnding = getEndingBalance(accountId, null);
  const scenarioEnding = getEndingBalance(accountId, scenarioId);
  return scenarioEnding - baseEnding;
};

// Event handlers
const handleAccountChange = () => {
  // Will trigger refresh when user clicks update button
};

const handleTimeframeChange = () => {
  // Will trigger refresh when user clicks update button
};

const selectAllAccounts = () => {
  selectedAccountIds.value = props.budget.accounts.map(account => account.id);
};

const deselectAllAccounts = () => {
  selectedAccountIds.value = [];
};

const refreshProjections = () => {
  if (selectedAccountIds.value.length === 0) return;
  
  refreshing.value = true;
  
  const params = {
    account_ids: selectedAccountIds.value,
    months: projectionMonths.value,
  };
  
  // Don't pass scenario_ids - let the backend use the is_active flag from database
  // This ensures we always get the latest scenario state
  
  router.get(
    route('budget.projections.multi-account', props.budget.id),
    params,
    {
      preserveState: false, // Changed to false to get fresh scenario data
      onFinish: () => {
        refreshing.value = false;
      },
    }
  );
};

const handleEditScenario = (scenario) => {
  editingScenario.value = scenario;
  showScenarioForm.value = true;
};

const handleSaveScenario = async (scenarioData) => {
  try {
    if (editingScenario.value) {
      await updateScenario(editingScenario.value.id, scenarioData);
    } else {
      await createScenario(scenarioData);
    }
    
    showScenarioForm.value = false;
    editingScenario.value = null;
    
    // Refresh projections to show new scenario
    refreshProjections();
  } catch (error) {
    console.error('Error saving scenario:', error);
    toast.error('Failed to save scenario. Please try again.');
  }
};

const handleDeleteScenario = async (scenarioId) => {
  const confirmed = await toast.confirm({
    title: 'Delete Scenario',
    message: 'Are you sure you want to delete this scenario?',
    confirmText: 'Delete',
    cancelText: 'Cancel',
    type: 'danger'
  });
  
  if (!confirmed) {
    return;
  }
  
  try {
    await deleteScenario(scenarioId);
    
    // Refresh projections
    refreshProjections();
  } catch (error) {
    console.error('Error deleting scenario:', error);
    toast.error('Failed to delete scenario. Please try again.');
  }
};

const handleToggleScenario = async (scenarioId) => {
  try {
    await toggleScenario(scenarioId);
    
    // Refresh projections to show/hide scenario
    refreshProjections();
  } catch (error) {
    console.error('Error toggling scenario:', error);
    toast.error('Failed to toggle scenario. Please try again.');
  }
};
</script>
