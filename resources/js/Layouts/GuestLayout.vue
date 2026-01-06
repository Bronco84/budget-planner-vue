<script setup>
import AnimatedBackground from '@/Components/AnimatedBackground.vue';
import { useTheme } from '@/composables/useTheme';

const { initializeTheme } = useTheme();

// Initialize theme on component mount
initializeTheme();
</script>

<template>
    <AnimatedBackground>
        <slot />
    </AnimatedBackground>
</template>
