<template>
  <div class="multi-account-chart-container">
    <Line
      :data="chartData"
      :options="chartOptions"
      :height="height"
    />
  </div>
</template>

<script setup>
import { computed } from 'vue';
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

// Register required Chart.js components
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
  accounts: {
    type: Array,
    required: true,
  },
  baseProjections: {
    type: Object,
    required: true,
  },
  scenarioProjections: {
    type: Object,
    default: () => ({}),
  },
  scenarios: {
    type: Array,
    default: () => [],
  },
  height: {
    type: Number,
    default: 400,
  },
});

// Color palette for accounts
const accountColors = [
  { border: '#3b82f6', background: 'rgba(59, 130, 246, 0.1)' }, // blue
  { border: '#10b981', background: 'rgba(16, 185, 129, 0.1)' }, // green
  { border: '#f59e0b', background: 'rgba(245, 158, 11, 0.1)' }, // amber
  { border: '#ef4444', background: 'rgba(239, 68, 68, 0.1)' }, // red
  { border: '#8b5cf6', background: 'rgba(139, 92, 246, 0.1)' }, // purple
];

// Format dates for chart labels
const formatDateForLabel = (dateString) => {
  if (!dateString) return '';
  
  try {
    const date = new Date(dateString);
    return date.toLocaleDateString(undefined, { 
      month: 'short', 
      day: 'numeric' 
    });
  } catch (e) {
    return dateString;
  }
};

// Helper to convert hex color to rgba
const hexToRgba = (hex, alpha = 1) => {
  const r = parseInt(hex.slice(1, 3), 16);
  const g = parseInt(hex.slice(3, 5), 16);
  const b = parseInt(hex.slice(5, 7), 16);
  return `rgba(${r}, ${g}, ${b}, ${alpha})`;
};

// Generate chart data
const chartData = computed(() => {
  const datasets = [];
  
  // Get all unique dates across all projections
  const allDates = new Set();
  Object.values(props.baseProjections).forEach(projection => {
    if (projection.days) {
      projection.days.forEach(day => allDates.add(day.date));
    }
  });
  
  const sortedDates = Array.from(allDates).sort();
  const labels = sortedDates.map(date => formatDateForLabel(date));
  
  // Create datasets for each account's base projection
  props.accounts.forEach((account, index) => {
    const accountColor = accountColors[index % accountColors.length];
    const projection = props.baseProjections[account.id];
    
    if (projection && projection.days) {
      const data = sortedDates.map(date => {
        const day = projection.days.find(d => d.date === date);
        return day ? day.balance / 100 : null;
      });
      
      datasets.push({
        label: `${account.name} (Base)`,
        data,
        borderColor: accountColor.border,
        backgroundColor: accountColor.background,
        borderWidth: 2,
        tension: 0.4,
        fill: false,
        pointRadius: 0, // No points on base line
        pointHoverRadius: 5,
      });
    }
  });
  
  // Create datasets for each scenario's projections
  Object.entries(props.scenarioProjections).forEach(([scenarioId, accountProjections]) => {
    const scenario = props.scenarios.find(s => s.id == scenarioId);
    if (!scenario) return;
    
    props.accounts.forEach((account, index) => {
      const projection = accountProjections[account.id];
      
      if (projection && projection.days) {
        const data = sortedDates.map(date => {
          const day = projection.days.find(d => d.date === date);
          return day ? day.balance / 100 : null;
        });
        
        // Use the same color as the base account but slightly darker/different
        const accountColor = accountColors[index % accountColors.length];
        const baseColor = accountColor.border;
        
        // Darken the color slightly for the scenario line
        // Parse the hex color and darken it
        const darkenColor = (hex) => {
          const r = Math.max(0, parseInt(hex.slice(1, 3), 16) - 40);
          const g = Math.max(0, parseInt(hex.slice(3, 5), 16) - 40);
          const b = Math.max(0, parseInt(hex.slice(5, 7), 16) - 40);
          return `#${r.toString(16).padStart(2, '0')}${g.toString(16).padStart(2, '0')}${b.toString(16).padStart(2, '0')}`;
        };
        
        const scenarioColor = darkenColor(baseColor);
        
        datasets.push({
          label: `${account.name} (${scenario.name})`,
          data,
          borderColor: scenarioColor,
          backgroundColor: hexToRgba(scenarioColor, 0.1),
          borderWidth: 4, // Thicker to be visible over base line
          borderDash: [10, 5], // Longer dashes for better visibility
          tension: 0.4,
          fill: false,
          pointRadius: 2, // Small points to show it's a different line
          pointHoverRadius: 6,
          pointStyle: 'circle',
        });
      }
    });
  });
  
  return {
    labels,
    datasets,
  };
});

// Chart options
const chartOptions = computed(() => {
  // Find min and max values across all datasets
  let minValue = Infinity;
  let maxValue = -Infinity;
  
  chartData.value.datasets.forEach(dataset => {
    dataset.data.forEach(value => {
      if (value !== null) {
        minValue = Math.min(minValue, value);
        maxValue = Math.max(maxValue, value);
      }
    });
  });
  
  // Add some padding to the range
  const range = maxValue - minValue;
  const padding = range * 0.1;
  
  return {
    responsive: true,
    maintainAspectRatio: false,
    interaction: {
      mode: 'index',
      intersect: false,
    },
    plugins: {
      legend: {
        position: 'top',
        labels: {
          usePointStyle: true,
          padding: 15,
          font: {
            size: 11,
          },
        },
      },
      tooltip: {
        callbacks: {
          title: function(context) {
            // Show full date in tooltip
            if (context[0] && context[0].label) {
              const dateIndex = context[0].dataIndex;
              const allDates = new Set();
              Object.values(props.baseProjections).forEach(projection => {
                if (projection.days) {
                  projection.days.forEach(day => allDates.add(day.date));
                }
              });
              const sortedDates = Array.from(allDates).sort();
              const date = sortedDates[dateIndex];
              if (date) {
                return new Date(date).toLocaleDateString(undefined, {
                  year: 'numeric',
                  month: 'long',
                  day: 'numeric',
                });
              }
            }
            return context[0]?.label || '';
          },
          label: function(context) {
            const value = context.raw;
            if (value === null) return '';
            return `${context.dataset.label}: ${formatCurrency(value * 100)}`;
          },
          afterBody: function(context) {
            // Calculate difference from base if this is a scenario projection
            if (context[0] && context[0].dataset.label.includes('(') && !context[0].dataset.label.includes('(Base)')) {
              const datasetIndex = context[0].datasetIndex;
              const dataIndex = context[0].dataIndex;
              const scenarioValue = context[0].raw;
              
              // Find corresponding base dataset
              const accountName = context[0].dataset.label.split(' (')[0];
              const baseDataset = chartData.value.datasets.find(ds => 
                ds.label === `${accountName} (Base)`
              );
              
              if (baseDataset && baseDataset.data[dataIndex] !== null && scenarioValue !== null) {
                const baseValue = baseDataset.data[dataIndex];
                const difference = scenarioValue - baseValue;
                const sign = difference >= 0 ? '+' : '';
                return [`Difference: ${sign}${formatCurrency(difference * 100)}`];
              }
            }
            return [];
          },
        },
      },
    },
    scales: {
      y: {
        grid: {
          display: true,
          drawBorder: true,
        },
        ticks: {
          callback: function(value) {
            return formatCurrency(value * 100);
          },
        },
        suggestedMin: minValue !== Infinity ? minValue - padding : 0,
        suggestedMax: maxValue !== -Infinity ? maxValue + padding : 1000,
      },
      x: {
        grid: {
          display: false,
        },
        ticks: {
          maxRotation: 45,
          minRotation: 45,
          autoSkip: true,
          maxTicksLimit: 20,
        },
      },
    },
  };
});
</script>

<style scoped>
.multi-account-chart-container {
  position: relative;
  width: 100%;
}
</style>

