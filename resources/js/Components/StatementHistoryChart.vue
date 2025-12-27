<template>
  <div class="statement-history-chart">
    <div v-if="loading" class="flex items-center justify-center h-64">
      <div class="text-gray-500 dark:text-gray-400">Loading statement history...</div>
    </div>

    <div v-else-if="error" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
      <p class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>
    </div>

    <div v-else-if="!hasData" class="bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-8 text-center">
      <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
      </svg>
      <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">No Statement History</h3>
      <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
        Statement history will appear here once you sync your credit card data.
      </p>
    </div>

    <div v-else class="space-y-4">
      <!-- Chart Header -->
      <div class="flex justify-between items-center">
        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300">
          Statement Balance History
        </h4>
        <div class="text-xs text-gray-500 dark:text-gray-400">
          Last {{ historyData.length }} statement{{ historyData.length !== 1 ? 's' : '' }}
        </div>
      </div>

      <!-- Chart -->
      <div class="bg-white dark:bg-gray-800 rounded-lg p-4 border border-gray-200 dark:border-gray-700">
        <Line
          :data="chartData"
          :options="chartOptions"
          :height="height"
        />
      </div>

      <!-- Summary Stats -->
      <div class="grid grid-cols-3 gap-4">
        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
          <div class="text-xs text-gray-500 dark:text-gray-400">Average Balance</div>
          <div class="text-lg font-semibold text-gray-900 dark:text-gray-100">
            {{ formatCurrency(averageBalance) }}
          </div>
        </div>
        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
          <div class="text-xs text-gray-500 dark:text-gray-400">Trend</div>
          <div class="text-lg font-semibold flex items-center" :class="trendClass">
            <svg v-if="trend > 0" class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6" />
            </svg>
            <svg v-else-if="trend < 0" class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
            </svg>
            {{ trendText }}
          </div>
        </div>
        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
          <div class="text-xs text-gray-500 dark:text-gray-400">Avg. Utilization</div>
          <div class="text-lg font-semibold" :class="utilizationClass">
            {{ averageUtilization }}%
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { Line } from 'vue-chartjs';
import {
  Chart as ChartJS,
  Title,
  Tooltip,
  Legend,
  LineElement,
  LinearScale,
  PointElement,
  CategoryScale,
} from 'chart.js';
import { formatCurrency } from '@/utils/format.js';

// Register Chart.js components
ChartJS.register(
  Title,
  Tooltip,
  Legend,
  LineElement,
  LinearScale,
  PointElement,
  CategoryScale
);

const props = defineProps({
  budgetId: {
    type: Number,
    required: true,
  },
  accountId: {
    type: Number,
    required: true,
  },
  height: {
    type: Number,
    default: 250,
  },
});

const loading = ref(true);
const error = ref(null);
const historyData = ref([]);

// Fetch statement history from API
const fetchStatementHistory = async () => {
  loading.value = true;
  error.value = null;

  try {
    const response = await fetch(
      `/budget/${props.budgetId}/account/${props.accountId}/plaid/statement-history`
    );

    if (!response.ok) {
      throw new Error('Failed to load statement history');
    }

    const data = await response.json();
    historyData.value = data.history || [];
  } catch (err) {
    error.value = err.message;
    console.error('Error fetching statement history:', err);
  } finally {
    loading.value = false;
  }
};

// Check if we have data
const hasData = computed(() => historyData.value.length > 0);

// Prepare chart data
const chartData = computed(() => {
  if (!hasData.value) return { labels: [], datasets: [] };

  const labels = historyData.value.map(item => formatDateLabel(item.statement_issue_date));
  const balances = historyData.value.map(item => item.statement_balance);
  const utilizations = historyData.value.map(item => item.credit_utilization_percentage || 0);

  return {
    labels,
    datasets: [
      {
        label: 'Statement Balance',
        data: balances,
        borderColor: 'rgb(59, 130, 246)', // blue-500
        backgroundColor: 'rgba(59, 130, 246, 0.1)',
        tension: 0.3,
        yAxisID: 'y',
        pointRadius: 4,
        pointHoverRadius: 6,
      },
      {
        label: 'Credit Utilization (%)',
        data: utilizations,
        borderColor: 'rgb(249, 115, 22)', // orange-500
        backgroundColor: 'rgba(249, 115, 22, 0.1)',
        tension: 0.3,
        yAxisID: 'y1',
        pointRadius: 4,
        pointHoverRadius: 6,
      },
    ],
  };
});

// Chart options
const chartOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  interaction: {
    mode: 'index',
    intersect: false,
  },
  plugins: {
    legend: {
      display: true,
      position: 'top',
      labels: {
        color: isDarkMode() ? '#d1d5db' : '#374151',
        usePointStyle: true,
        padding: 15,
      },
    },
    tooltip: {
      backgroundColor: isDarkMode() ? '#1f2937' : '#ffffff',
      titleColor: isDarkMode() ? '#f3f4f6' : '#111827',
      bodyColor: isDarkMode() ? '#d1d5db' : '#374151',
      borderColor: isDarkMode() ? '#374151' : '#e5e7eb',
      borderWidth: 1,
      padding: 12,
      displayColors: true,
      callbacks: {
        label: function(context) {
          let label = context.dataset.label || '';
          if (label) {
            label += ': ';
          }
          if (context.datasetIndex === 0) {
            // Balance - format as currency
            label += formatCurrency(context.parsed.y * 100); // Convert back to cents
          } else {
            // Utilization - show as percentage
            label += context.parsed.y.toFixed(1) + '%';
          }
          return label;
        },
      },
    },
  },
  scales: {
    x: {
      grid: {
        color: isDarkMode() ? '#374151' : '#e5e7eb',
      },
      ticks: {
        color: isDarkMode() ? '#9ca3af' : '#6b7280',
      },
    },
    y: {
      type: 'linear',
      display: true,
      position: 'left',
      grid: {
        color: isDarkMode() ? '#374151' : '#e5e7eb',
      },
      ticks: {
        color: isDarkMode() ? '#9ca3af' : '#6b7280',
        callback: function(value) {
          return '$' + (value / 1).toFixed(0);
        },
      },
      title: {
        display: true,
        text: 'Statement Balance',
        color: isDarkMode() ? '#d1d5db' : '#374151',
      },
    },
    y1: {
      type: 'linear',
      display: true,
      position: 'right',
      min: 0,
      max: 100,
      grid: {
        drawOnChartArea: false,
      },
      ticks: {
        color: isDarkMode() ? '#9ca3af' : '#6b7280',
        callback: function(value) {
          return value + '%';
        },
      },
      title: {
        display: true,
        text: 'Utilization %',
        color: isDarkMode() ? '#d1d5db' : '#374151',
      },
    },
  },
}));

// Calculate summary statistics
const averageBalance = computed(() => {
  if (!hasData.value) return 0;
  const sum = historyData.value.reduce((acc, item) => acc + item.statement_balance_cents, 0);
  return Math.round(sum / historyData.value.length);
});

const averageUtilization = computed(() => {
  if (!hasData.value) return 0;
  const validUtilizations = historyData.value.filter(item => item.credit_utilization_percentage !== null);
  if (validUtilizations.length === 0) return 0;
  const sum = validUtilizations.reduce((acc, item) => acc + item.credit_utilization_percentage, 0);
  return (sum / validUtilizations.length).toFixed(1);
});

const trend = computed(() => {
  if (historyData.value.length < 2) return 0;
  const oldest = historyData.value[0].statement_balance_cents;
  const newest = historyData.value[historyData.value.length - 1].statement_balance_cents;
  return newest - oldest;
});

const trendText = computed(() => {
  if (trend.value > 0) return 'Increasing';
  if (trend.value < 0) return 'Decreasing';
  return 'Stable';
});

const trendClass = computed(() => {
  if (trend.value > 0) return 'text-red-600 dark:text-red-400';
  if (trend.value < 0) return 'text-green-600 dark:text-green-400';
  return 'text-gray-600 dark:text-gray-400';
});

const utilizationClass = computed(() => {
  const util = parseFloat(averageUtilization.value);
  if (util >= 70) return 'text-red-600 dark:text-red-400';
  if (util >= 30) return 'text-orange-600 dark:text-orange-400';
  return 'text-green-600 dark:text-green-400';
});

// Format date for chart labels
const formatDateLabel = (dateString) => {
  const date = new Date(dateString);
  return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
};

// Detect dark mode
const isDarkMode = () => {
  return document.documentElement.classList.contains('dark');
};

// Fetch data on mount
onMounted(() => {
  fetchStatementHistory();
});

// Watch for account changes
watch(() => props.accountId, () => {
  fetchStatementHistory();
});
</script>

<style scoped>
.statement-history-chart {
  @apply w-full;
}
</style>
