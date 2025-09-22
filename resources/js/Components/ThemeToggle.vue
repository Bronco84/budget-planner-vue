<template>
  <button
    @click="toggleTheme"
    :title="themeLabel"
    class="inline-flex items-center px-3 py-2 text-sm leading-4 font-medium rounded-md transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 
           text-gray-500 hover:text-gray-700 hover:bg-gray-50 
           dark:text-gray-400 dark:hover:text-gray-200 dark:hover:bg-gray-700 dark:focus:ring-offset-gray-800"
  >
    <span class="text-lg mr-2">{{ themeIcon }}</span>
    <span class="hidden sm:inline">{{ isDark ? 'Light' : 'Dark' }}</span>
  </button>
</template>

<script setup>
import { useTheme } from '@/composables/useTheme'

const { isDark, toggleTheme, themeIcon, themeLabel } = useTheme()
</script>
