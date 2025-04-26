<template>
  <div v-if="links.length > 3">
    <div class="flex flex-wrap -mb-1">
      <template v-for="(link, index) in links" :key="index">
        <div
          v-if="link.url === null"
          class="mr-1 mb-1 px-4 py-2 text-sm text-gray-400 border rounded"
          :class="{ 'ml-auto': index === 0 }"
        >
          <span v-html="link.label" />
        </div>

        <Link
          v-else
          class="mr-1 mb-1 px-4 py-2 text-sm border rounded hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
          :class="{
            'ml-auto': index === 0,
            'bg-blue-500 text-white hover:bg-blue-600': link.active,
            'text-gray-700': !link.active
          }"
          :href="link.url"
        >
          <span v-html="link.label" />
        </Link>
      </template>
    </div>
  </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
  links: Array,
});
</script> 