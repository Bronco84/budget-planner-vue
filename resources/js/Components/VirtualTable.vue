<template>
  <div class="virtual-table-container" :style="{ height: height }">
    <!-- Fixed Header -->
    <div class="virtual-table-header" ref="headerRef">
      <table class="min-w-full table-fixed">
        <thead class="bg-gray-50 dark:bg-gray-700">
          <slot name="header"></slot>
        </thead>
      </table>
    </div>

    <!-- Scrollable Body with Virtual Scrolling -->
    <div class="virtual-table-body" ref="bodyRef" @scroll="handleScroll">
      <RecycleScroller
        v-if="items.length > 0"
        :items="items"
        :item-size="itemSize"
        :buffer="buffer"
        key-field="id"
        v-slot="{ item, index }"
        class="scroller"
      >
        <slot name="row" :item="item" :index="index"></slot>
      </RecycleScroller>

      <!-- Empty State -->
      <div v-else class="flex items-center justify-center py-12">
        <slot name="empty">
          <div class="text-center">
            <p class="text-gray-500 dark:text-gray-400">No data available</p>
          </div>
        </slot>
      </div>

      <!-- Loading Indicator -->
      <div v-if="loading" class="flex items-center justify-center py-4 border-t border-gray-200 dark:border-gray-700">
        <svg class="animate-spin h-5 w-5 text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <span class="ml-2 text-sm text-gray-600 dark:text-gray-400">Loading more...</span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { RecycleScroller } from 'vue3-virtual-scroller';
import 'vue3-virtual-scroller/dist/vue3-virtual-scroller.css';

const props = defineProps({
  items: {
    type: Array,
    required: true,
  },
  itemSize: {
    type: Number,
    default: 65, // Default row height in pixels
  },
  height: {
    type: String,
    default: '600px',
  },
  buffer: {
    type: Number,
    default: 200, // Extra pixels to render outside viewport
  },
  loading: {
    type: Boolean,
    default: false,
  },
  hasMore: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['load-more']);

const headerRef = ref(null);
const bodyRef = ref(null);

// Handle scroll for infinite loading
const handleScroll = (event) => {
  const { scrollTop, scrollHeight, clientHeight } = event.target;
  
  // Check if scrolled near bottom (within 100px)
  if (scrollHeight - scrollTop - clientHeight < 100) {
    if (props.hasMore && !props.loading) {
      emit('load-more');
    }
  }
};

// Sync header scroll with body scroll
const syncScroll = () => {
  if (headerRef.value && bodyRef.value) {
    headerRef.value.scrollLeft = bodyRef.value.scrollLeft;
  }
};

onMounted(() => {
  if (bodyRef.value) {
    bodyRef.value.addEventListener('scroll', syncScroll);
  }
});

onUnmounted(() => {
  if (bodyRef.value) {
    bodyRef.value.removeEventListener('scroll', syncScroll);
  }
});
</script>

<style scoped>
.virtual-table-container {
  display: flex;
  flex-direction: column;
  border: 1px solid #e5e7eb;
  border-radius: 0.5rem;
  overflow: hidden;
}

.dark .virtual-table-container {
  border-color: #374151;
}

.virtual-table-header {
  overflow-x: hidden;
  border-bottom: 1px solid #e5e7eb;
}

.dark .virtual-table-header {
  border-color: #374151;
}

.virtual-table-body {
  flex: 1;
  overflow-x: auto;
  overflow-y: auto;
}

.scroller {
  height: 100%;
}

/* Ensure table takes full width */
.virtual-table-header table,
.virtual-table-body table {
  width: 100%;
}

/* Hide scrollbar on header */
.virtual-table-header::-webkit-scrollbar {
  display: none;
}

.virtual-table-header {
  -ms-overflow-style: none;
  scrollbar-width: none;
}
</style>

