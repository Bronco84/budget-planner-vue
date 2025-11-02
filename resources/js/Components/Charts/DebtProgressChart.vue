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
    default: 300
  }
});

const chartData = computed(() => {
  const debts = props.data || [];

  return {
    labels: debts.map(debt => debt.accountName),
    datasets: [
      {
        label: 'Paid Off',
        data: debts.map(debt => debt.paidOff / 100),
        backgroundColor: 'rgba(16, 185, 129, 0.7)', // Green
        borderColor: '#10B981',
        borderWidth: 1,
      },
      {
        label: 'Remaining',
        data: debts.map(debt => debt.currentBalance / 100),
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
    x: {
      stacked: true,
      grid: {
        display: false,
      },
    },
    y: {
      stacked: true,
      beginAtZero: true,
      grid: {
        display: true,
      },
      ticks: {
        callback: function(value) {
          return formatCurrency(value * 100);
        }
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
