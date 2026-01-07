import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';

export function useMultiAccountProjection(budgetId) {
  const selectedAccountIds = ref([]);
  const activeScenarioIds = ref([]);
  const monthsAhead = ref(12);
  const loading = ref(false);

  /**
   * Add an account to the selection
   */
  const addAccount = (accountId) => {
    if (!selectedAccountIds.value.includes(accountId)) {
      selectedAccountIds.value.push(accountId);
    }
  };

  /**
   * Remove an account from the selection
   */
  const removeAccount = (accountId) => {
    selectedAccountIds.value = selectedAccountIds.value.filter(id => id !== accountId);
  };

  /**
   * Toggle an account in the selection
   */
  const toggleAccount = (accountId) => {
    if (selectedAccountIds.value.includes(accountId)) {
      removeAccount(accountId);
    } else {
      addAccount(accountId);
    }
  };

  /**
   * Set the selected accounts
   */
  const setAccounts = (accountIds) => {
    selectedAccountIds.value = accountIds;
  };

  /**
   * Add a scenario to active scenarios
   */
  const addScenario = (scenarioId) => {
    if (!activeScenarioIds.value.includes(scenarioId)) {
      activeScenarioIds.value.push(scenarioId);
    }
  };

  /**
   * Remove a scenario from active scenarios
   */
  const removeScenario = (scenarioId) => {
    activeScenarioIds.value = activeScenarioIds.value.filter(id => id !== scenarioId);
  };

  /**
   * Toggle a scenario in active scenarios
   */
  const toggleScenario = (scenarioId) => {
    if (activeScenarioIds.value.includes(scenarioId)) {
      removeScenario(scenarioId);
    } else {
      addScenario(scenarioId);
    }
  };

  /**
   * Set the active scenarios
   */
  const setScenarios = (scenarioIds) => {
    activeScenarioIds.value = scenarioIds;
  };

  /**
   * Set the projection timeframe
   */
  const setMonthsAhead = (months) => {
    monthsAhead.value = months;
  };

  /**
   * Fetch projections with current settings
   */
  const fetchProjections = () => {
    loading.value = true;
    
    const params = {
      months: monthsAhead.value,
    };
    
    if (selectedAccountIds.value.length > 0) {
      params.account_ids = selectedAccountIds.value;
    }
    
    if (activeScenarioIds.value.length > 0) {
      params.scenario_ids = activeScenarioIds.value;
    }
    
    router.get(
      route('budget.projections.multi-account', budgetId),
      params,
      {
        preserveState: true,
        preserveScroll: true,
        onFinish: () => {
          loading.value = false;
        },
      }
    );
  };

  /**
   * Update URL with current settings without reloading
   */
  const updateUrl = () => {
    const params = new URLSearchParams();
    
    params.set('months', monthsAhead.value);
    
    if (selectedAccountIds.value.length > 0) {
      selectedAccountIds.value.forEach(id => {
        params.append('account_ids[]', id);
      });
    }
    
    if (activeScenarioIds.value.length > 0) {
      activeScenarioIds.value.forEach(id => {
        params.append('scenario_ids[]', id);
      });
    }
    
    const url = `${route('budget.projections.multi-account', budgetId)}?${params.toString()}`;
    window.history.replaceState({}, '', url);
  };

  /**
   * Initialize from URL parameters
   */
  const initializeFromUrl = () => {
    const params = new URLSearchParams(window.location.search);
    
    // Get months
    const months = params.get('months');
    if (months) {
      monthsAhead.value = parseInt(months);
    }
    
    // Get account IDs
    const accountIds = params.getAll('account_ids[]');
    if (accountIds.length > 0) {
      selectedAccountIds.value = accountIds.map(id => parseInt(id));
    }
    
    // Get scenario IDs
    const scenarioIds = params.getAll('scenario_ids[]');
    if (scenarioIds.length > 0) {
      activeScenarioIds.value = scenarioIds.map(id => parseInt(id));
    }
  };

  return {
    selectedAccountIds,
    activeScenarioIds,
    monthsAhead,
    loading,
    addAccount,
    removeAccount,
    toggleAccount,
    setAccounts,
    addScenario,
    removeScenario,
    toggleScenario,
    setScenarios,
    setMonthsAhead,
    fetchProjections,
    updateUrl,
    initializeFromUrl,
  };
}




