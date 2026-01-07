<script setup>
import AnimatedBackground from '@/Components/AnimatedBackground.vue';
import { useTheme } from '@/composables/useTheme';
import { Toaster } from 'vue-sonner';
import { 
    CheckCircleIcon, 
    XCircleIcon, 
    ExclamationTriangleIcon, 
    InformationCircleIcon 
} from '@heroicons/vue/24/outline';

const { initializeTheme } = useTheme();

// Initialize theme on component mount
initializeTheme();
</script>

<template>
    <AnimatedBackground>
        <slot />
        <Toaster>
            <template #success-icon>
                <CheckCircleIcon class="w-5 h-5" />
            </template>
            <template #error-icon>
                <XCircleIcon class="w-5 h-5" />
            </template>
            <template #warning-icon>
                <ExclamationTriangleIcon class="w-5 h-5" />
            </template>
            <template #info-icon>
                <InformationCircleIcon class="w-5 h-5" />
            </template>
        </Toaster>
    </AnimatedBackground>
</template>
