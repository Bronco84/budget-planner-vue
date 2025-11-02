<script setup>
import { computed } from 'vue';
import { Doughnut } from 'vue-chartjs';
import {
  Chart as ChartJS,
  Title,
  Tooltip,
  Legend,
  ArcElement
} from 'chart.js';
import { formatCurrency } from '@/utils/format.js';

ChartJS.register(
  Title,
  Tooltip,
  Legend,
  ArcElement
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

const colors = [
  '#3B82F6', // Blue
  '#10B981', // Green
  '#F59E0B', // Yellow
  '#EF4444', // Red
  '#8B5CF6', // Purple
  '#EC4899', // Pink
  '#14B8A6', // Teal
  '#F97316', // Orange
  '#6366F1', // Indigo
  '#84CC16', // Lime
];

const chartData = computed(() => {
  const categories = props.data || [];

  return {
    labels: categories.map(cat => cat.category),
    datasets: [{
      data: categories.map(cat => cat.total / 100),
      backgroundColor: categories.map((_, index) => colors[index % colors.length]),
      borderWidth: 2,
      borderColor: '#fff',
    }]
  };
});

const chartOptions = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      position: 'right',
    },
    tooltip: {
      callbacks: {
        label: function(context) {
          const label = context.label || '';
          const value = formatCurrency(context.raw * 100);
          const total = context.dataset.data.reduce((acc, val) => acc + val, 0);
          const percentage = ((context.raw / total) * 100).toFixed(1);
          return `${label}: ${value} (${percentage}%)`;
        }
      }
    },
  },
}));
</script>

<template>
  <div class="chart-container">
    <Doughnut
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
