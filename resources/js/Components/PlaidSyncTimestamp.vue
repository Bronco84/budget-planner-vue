<template>
  <span v-if="timestamp" :class="textClass">
    {{ formattedTimestamp }}
  </span>
  <span v-else :class="textClass">
    {{ neverText }}
  </span>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  timestamp: {
    type: String,
    default: null,
  },
  format: {
    type: String,
    default: 'relative', // 'relative' or 'absolute'
    validator: (value) => ['relative', 'absolute'].includes(value),
  },
  textClass: {
    type: String,
    default: '',
  },
  neverText: {
    type: String,
    default: 'Never',
  },
});

const formattedTimestamp = computed(() => {
  if (!props.timestamp) return props.neverText;

  try {
    const date = new Date(props.timestamp);

    if (props.format === 'absolute') {
      // Format as absolute date/time: "11/1/2025, 4:08:35 PM"
      return date.toLocaleString();
    } else {
      // Format as relative time: "6 hours ago"
      const now = new Date();
      const diffMs = now - date;
      const diffMins = Math.floor(diffMs / (1000 * 60));
      const diffHours = Math.floor(diffMs / (1000 * 60 * 60));
      const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

      if (diffMins < 1) {
        return 'just now';
      } else if (diffMins < 60) {
        return `${diffMins} minute${diffMins !== 1 ? 's' : ''} ago`;
      } else if (diffHours < 24) {
        return `${diffHours} hour${diffHours !== 1 ? 's' : ''} ago`;
      } else {
        return `${diffDays} day${diffDays !== 1 ? 's' : ''} ago`;
      }
    }
  } catch (e) {
    return props.timestamp;
  }
});
</script>
