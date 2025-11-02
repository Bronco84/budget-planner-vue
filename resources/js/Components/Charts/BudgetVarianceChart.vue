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
    type: Array,
    required: true,
  },
  height: {
    type: Number,
    default: 400
  }
});

const chartData = computed(() => {
  const categories = props.data || [];

  return {
    labels: categories.map(cat => cat.category),
    datasets: [
      {
        label: 'Allocated',
        data: categories.map(cat => cat.allocated / 100),
        backgroundColor: 'rgba(59, 130, 246, 0.7)', // Blue
        borderColor: '#3B82F6',
        borderWidth: 1,
      },
      {
        label: 'Spent',
        data: categories.map(cat => cat.spent / 100),
        backgroundColor: categories.map(cat =>
          cat.isOverBudget ? 'rgba(239, 68, 68, 0.7)' : 'rgba(16, 185, 129, 0.7)'
        ),
        borderColor: categories.map(cat =>
          cat.isOverBudget ? '#EF4444' : '#10B981'
        ),
        borderWidth: 1,
      }
    ]
  };
});

const chartOptions = computed(() => ({
  indexAxis: 'y', // Horizontal bars
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
    x: {
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
    y: {
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
