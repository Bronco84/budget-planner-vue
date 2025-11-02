<script setup>
import { computed } from 'vue';
import { Bar } from 'vue-chartjs';
import {
  Chart as ChartJS,
  Title,
  Tooltip,
  Legend,
  BarElement,
  CategoryScale,
  LinearScale
} from 'chart.js';
import { formatCurrency } from '@/utils/format.js';

ChartJS.register(
  Title,
  Tooltip,
  Legend,
  BarElement,
  CategoryScale,
  LinearScale
);

const props = defineProps({
  data: {
    type: Object,
    required: true,
  },
  height: {
    type: Number,
    default: 300
  }
});

const chartData = computed(() => {
  const monthlyData = props.data.monthly || [];

  return {
    labels: monthlyData.map(month => month.month),
    datasets: [
      {
        label: 'Income',
        data: monthlyData.map(month => month.income / 100),
        backgroundColor: 'rgba(16, 185, 129, 0.7)', // Green
        borderColor: '#10B981',
        borderWidth: 1,
      },
      {
        label: 'Expenses',
        data: monthlyData.map(month => month.expenses / 100),
        backgroundColor: 'rgba(239, 68, 68, 0.7)', // Red
        borderColor: '#EF4444',
        borderWidth: 1,
      }
    ]
  };
});

const chartOptions = computed(() => ({
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
          return `${context.dataset.label}: ${formatCurrency(context.raw * 100)}`;
        }
      }
    },
  },
  scales: {
    y: {
      beginAtZero: true,
      grid: {
        display: true,
      },
      ticks: {
        callback: function(value) {
          return formatCurrency(value * 100);
        }
      },
    },
    x: {
      grid: {
        display: false,
      },
    }
  }
}));
</script>

<template>
  <div class="chart-container">
    <Bar
      :data="chartData"
      :options="chartOptions"
      :height="height"
    />
  </div>
</template>

<style scoped>
.chart-container {
  position: relative;
  width: 100%;
}
</style>
