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
  },
  title: {
    type: String,
    default: ''
  },
  useCurrencyFormat: {
    type: Boolean,
    default: true
  }
});

const chartData = computed(() => {
  return {
    labels: props.data.labels || [],
    datasets: (props.data.datasets || []).map(dataset => ({
      ...dataset,
      tension: 0.4,
      pointRadius: 2,
      pointHoverRadius: 5,
    }))
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
    title: {
      display: !!props.title,
      text: props.title
    },
    tooltip: {
      callbacks: {
        label: function(context) {
          const value = props.useCurrencyFormat
            ? formatCurrency(context.raw * 100)
            : context.raw;
          return `${context.dataset.label}: ${value}`;
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
          return props.useCurrencyFormat
            ? formatCurrency(value * 100)
            : value;
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
