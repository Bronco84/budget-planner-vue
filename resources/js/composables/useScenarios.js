import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';

export function useScenarios(budgetId) {
  const scenarios = ref([]);
  const loading = ref(false);
  const error = ref(null);

  /**
   * Fetch all scenarios for the budget
   */
  const fetchScenarios = async () => {
    loading.value = true;
    error.value = null;

    try {
      const response = await axios.get(route('budgets.scenarios.index', budgetId));
      scenarios.value = response.data.scenarios;
      return response.data.scenarios;
    } catch (e) {
      error.value = e.response?.data?.message || 'Failed to fetch scenarios';
      console.error('Error fetching scenarios:', e);
      throw e;
    } finally {
      loading.value = false;
    }
  };

  /**
   * Create a new scenario
   */
  const createScenario = async (scenarioData) => {
    loading.value = true;
    error.value = null;

    try {
      const response = await axios.post(
        route('budgets.scenarios.store', budgetId),
        scenarioData
      );
      
      // Add to local scenarios array
      scenarios.value.push(response.data.scenario);
      
      return response.data.scenario;
    } catch (e) {
      error.value = e.response?.data?.message || 'Failed to create scenario';
      console.error('Error creating scenario:', e);
      throw e;
    } finally {
      loading.value = false;
    }
  };

  /**
   * Update an existing scenario
   */
  const updateScenario = async (scenarioId, scenarioData) => {
    loading.value = true;
    error.value = null;

    try {
      const response = await axios.patch(
        route('budgets.scenarios.update', { budget: budgetId, scenario: scenarioId }),
        scenarioData
      );
      
      // Update in local scenarios array
      const index = scenarios.value.findIndex(s => s.id === scenarioId);
      if (index !== -1) {
        scenarios.value[index] = response.data.scenario;
      }
      
      return response.data.scenario;
    } catch (e) {
      error.value = e.response?.data?.message || 'Failed to update scenario';
      console.error('Error updating scenario:', e);
      throw e;
    } finally {
      loading.value = false;
    }
  };

  /**
   * Delete a scenario
   */
  const deleteScenario = async (scenarioId) => {
    loading.value = true;
    error.value = null;

    try {
      await axios.delete(
        route('budgets.scenarios.destroy', { budget: budgetId, scenario: scenarioId })
      );
      
      // Remove from local scenarios array
      scenarios.value = scenarios.value.filter(s => s.id !== scenarioId);
      
      return true;
    } catch (e) {
      error.value = e.response?.data?.message || 'Failed to delete scenario';
      console.error('Error deleting scenario:', e);
      throw e;
    } finally {
      loading.value = false;
    }
  };

  /**
   * Toggle scenario active state
   */
  const toggleScenario = async (scenarioId) => {
    loading.value = true;
    error.value = null;

    try {
      const response = await axios.post(
        route('budgets.scenarios.toggle', { budget: budgetId, scenario: scenarioId })
      );
      
      // Update in local scenarios array
      const index = scenarios.value.findIndex(s => s.id === scenarioId);
      if (index !== -1) {
        scenarios.value[index].is_active = response.data.is_active;
      }
      
      return response.data.is_active;
    } catch (e) {
      error.value = e.response?.data?.message || 'Failed to toggle scenario';
      console.error('Error toggling scenario:', e);
      throw e;
    } finally {
      loading.value = false;
    }
  };

  return {
    scenarios,
    loading,
    error,
    fetchScenarios,
    createScenario,
    updateScenario,
    deleteScenario,
    toggleScenario,
  };
}

