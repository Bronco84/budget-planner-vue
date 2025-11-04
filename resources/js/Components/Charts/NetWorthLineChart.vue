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
  Filler
} from 'chart.js';
import { formatCurrency } from '@/utils/format.js';

ChartJS.register(
  Title,
  Tooltip,
  Legend,
  LineElement,
  LinearScale,
  PointElement,
  CategoryScale,
  Filler
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

const formatDateForLabel = (dateString) => {
  if (!dateString) return '';
  try {
    const date = new Date(dateString);
    return date.toLocaleDateString(undefined, {
      month: 'short',
      day: 'numeric',
      year: '2-digit'
    });
  } catch (e) {
    return dateString;
  }
};

const chartData = computed(() => {
  const dataPoints = props.data.dataPoints || [];
  const labels = dataPoints.map(point => formatDateForLabel(point.date));

  return {
    labels,
    datasets: [
      {
        label: 'Net Worth',
        data: dataPoints.map(point => point.netWorth / 100),
        borderColor: '#10B981', // Green
        backgroundColor: 'rgba(16, 185, 129, 0.1)',
        tension: 0.4,
        fill: true,
        pointRadius: 2,
        pointHoverRadius: 5,
        borderWidth: 2,
      },
      {
        label: 'Assets',
        data: dataPoints.map(point => point.assets / 100),
        borderColor: '#3B82F6', // Blue
        backgroundColor: 'rgba(59, 130, 246, 0.05)',
        tension: 0.4,
        fill: false,
        pointRadius: 1,
        pointHoverRadius: 4,
        borderWidth: 1,
        borderDash: [5, 5],
      },
      {
        label: 'Liabilities',
        data: dataPoints.map(point => point.liabilities / 100),
        borderColor: '#EF4444', // Red
        backgroundColor: 'rgba(239, 68, 68, 0.05)',
        tension: 0.4,
        fill: false,
        pointRadius: 1,
        pointHoverRadius: 4,
        borderWidth: 1,
        borderDash: [5, 5],
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
      grid: {
        display: true,
        drawBorder: true,
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
      ticks: {
        maxRotation: 45,
        minRotation: 45,
      }
    }
  }
}));
</script>

<template>
  <div class="chart-container">
    <Line
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
