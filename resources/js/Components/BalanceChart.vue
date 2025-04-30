<template>
  <div class="balance-chart-container">
    <Line
      :data="chartData"
      :options="chartOptions"
      :height="height"
    />
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
  balanceData: {
    type: Array,
    required: true,
  },
  height: {
    type: Number,
    default: 300
  },
  showPositiveNegative: {
    type: Boolean,
    default: true
  }
});

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

// Format currency amounts
const formatCurrency = (amount) => {
  const dollars = Math.abs(amount) / 100;
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2
  }).format(dollars);
};

// Define chart data
const chartData = computed(() => {
  if (!props.balanceData || !Array.isArray(props.balanceData) || props.balanceData.length === 0) {
    return {
      labels: [],
      datasets: [
        {
          label: 'Account Balance',
          data: [],
          borderColor: '#4F46E5',
          backgroundColor: 'rgba(79, 70, 229, 0.1)',
          tension: 0.4,
          fill: true,
        }
      ]
    };
  }

  const labels = props.balanceData.map(day => formatDateForLabel(day.date));
  const dataPoints = props.balanceData.map(day => day.balance / 100); // Convert cents to dollars

  return {
    labels,
    datasets: [
      {
        label: 'Account Balance',
        data: dataPoints,
        borderColor: '#4F46E5', // Indigo
        backgroundColor: 'rgba(79, 70, 229, 0.1)',
        tension: 0.4,
        fill: true,
        pointRadius: 2,
        pointHoverRadius: 5,
      }
    ]
  };
});

// Define chart options
const chartOptions = computed(() => {
  if (!props.balanceData || !Array.isArray(props.balanceData) || props.balanceData.length === 0) {
    return {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: {
          suggestedMin: 0,
        }
      }
    };
  }

  const hasNegativeBalance = props.balanceData.some(day => day.balance < 0);
  
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
      },
      tooltip: {
        callbacks: {
          label: function(context) {
            return `Balance: ${formatCurrency(context.raw * 100)}`;
          }
        }
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
          }
        },
        suggestedMin: props.showPositiveNegative && hasNegativeBalance ? Math.min(...props.balanceData.map(day => day.balance / 100)) * 1.1 : 0,
      },
      x: {
        grid: {
          display: false,
        },
        ticks: {
          maxRotation: 45,
          minRotation: 45,
        }
      }
    }
  };
});

// Watch for data changes
watch(() => props.balanceData, () => {
  // Chart will update automatically since we're using computed properties
}, { deep: true });
</script>

<style scoped>
.balance-chart-container {
  position: relative;
  width: 100%;
}
</style> 