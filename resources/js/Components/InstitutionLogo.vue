<template>
  <div 
    class="flex items-center justify-center rounded-full overflow-hidden flex-shrink-0"
    :class="sizeClasses"
  >
    <!-- Logo image (backend provides the best available source) -->
    <img 
      v-if="account?.logo_src && !imageError" 
      :src="account.logo_src" 
      :alt="account?.institution_name || 'Institution logo'"
      class="w-full h-full object-contain bg-white"
      @error="handleImageError"
    />
    
    <!-- Fallback: Colored initials circle -->
    <div 
      v-else
      class="w-full h-full flex items-center justify-center text-white font-semibold"
      :class="[account?.initials_bg_color || 'bg-gray-500', fallbackTextClass]"
    >
      {{ account?.initials || '?' }}
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
  // The account object containing logo_src, initials, initials_bg_color, and institution_name
  account: {
    type: Object,
    required: true
  },
  // Size: 'xs', 'sm', 'md', 'lg', 'xl'
  size: {
    type: String,
    default: 'md',
    validator: (value) => ['xs', 'sm', 'md', 'lg', 'xl'].includes(value)
  }
});

const imageError = ref(false);

// Handle image load errors - show initials fallback
const handleImageError = () => {
  imageError.value = true;
};

// Size classes for the container
const sizeClasses = computed(() => {
  const sizes = {
    'xs': 'w-6 h-6',
    'sm': 'w-8 h-8',
    'md': 'w-10 h-10',
    'lg': 'w-12 h-12',
    'xl': 'w-16 h-16'
  };
  return sizes[props.size];
});

// Text size classes for fallback initials
const fallbackTextClass = computed(() => {
  const textSizes = {
    'xs': 'text-[10px]',
    'sm': 'text-xs',
    'md': 'text-sm',
    'lg': 'text-base',
    'xl': 'text-lg'
  };
  return textSizes[props.size];
});
</script>
