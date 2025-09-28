<template>
  <nav class="flex" aria-label="Breadcrumb" v-if="breadcrumbs && breadcrumbs.length > 0">
    <ol class="flex items-center space-x-2">
      <li v-for="(breadcrumb, index) in breadcrumbs" :key="index" class="flex items-center">
        <template v-if="index > 0">
          <svg class="mx-2 h-4 w-4 text-gray-400 dark:text-gray-500" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
          </svg>
        </template>
        <Link
          v-if="breadcrumb.url && index < breadcrumbs.length - 1"
          :href="breadcrumb.url"
          class="text-sm font-medium text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-colors duration-150"
        >
          {{ breadcrumb.title }}
        </Link>
        <span
          v-else
          class="text-sm font-medium text-gray-900 dark:text-gray-100"
          :class="{ 'text-gray-500 dark:text-gray-400': index < breadcrumbs.length - 1 }"
        >
          {{ breadcrumb.title }}
        </span>
      </li>
    </ol>
  </nav>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
  breadcrumbs: {
    type: Array,
    default: () => []
  }
});
</script>