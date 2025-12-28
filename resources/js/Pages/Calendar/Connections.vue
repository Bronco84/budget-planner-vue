<template>
  <AuthenticatedLayout>
    <Head title="Calendar Connections" />

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <div class="flex justify-between items-center mb-6">
              <div>
                <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100">
                  Calendar Connections
                </h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                  Connect your Google Calendar to track important financial dates like promotional balance end dates.
                </p>
              </div>
              
              <Link
                :href="route('calendar.connect.google')"
                class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 focus:bg-blue-700 active:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition ease-in-out duration-150"
              >
                <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M12.48 10.92v3.28h7.84c-.24 1.84-.853 3.187-1.787 4.133-1.147 1.147-2.933 2.4-6.053 2.4-4.827 0-8.6-3.893-8.6-8.72s3.773-8.72 8.6-8.72c2.6 0 4.507 1.027 5.907 2.347l2.307-2.307C18.747 1.44 16.133 0 12.48 0 5.867 0 .307 5.387.307 12s5.56 12 12.173 12c3.573 0 6.267-1.173 8.373-3.36 2.16-2.16 2.84-5.213 2.84-7.667 0-.76-.053-1.467-.173-2.053H12.48z"/>
                </svg>
                Connect Google Calendar
              </Link>
            </div>

            <!-- Connections List -->
            <div v-if="connections.length > 0" class="space-y-4">
              <div
                v-for="connection in connections"
                :key="connection.id"
                class="border border-gray-200 dark:border-gray-700 rounded-lg p-4"
              >
                <div class="flex items-center justify-between">
                  <div class="flex-1">
                    <div class="flex items-center">
                      <svg class="w-6 h-6 text-blue-600 mr-3" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12.48 10.92v3.28h7.84c-.24 1.84-.853 3.187-1.787 4.133-1.147 1.147-2.933 2.4-6.053 2.4-4.827 0-8.6-3.893-8.6-8.72s3.773-8.72 8.6-8.72c2.6 0 4.507 1.027 5.907 2.347l2.307-2.307C18.747 1.44 16.133 0 12.48 0 5.867 0 .307 5.387.307 12s5.56 12 12.173 12c3.573 0 6.267-1.173 8.373-3.36 2.16-2.16 2.84-5.213 2.84-7.667 0-.76-.053-1.467-.173-2.053H12.48z"/>
                      </svg>
                      <div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                          {{ connection.calendar_name }}
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                          {{ connection.events_count }} events synced
                          <span v-if="connection.last_synced_at" class="ml-2">
                            • Last synced {{ connection.last_synced_at }}
                          </span>
                        </p>
                      </div>
                    </div>
                  </div>

                  <div class="flex items-center space-x-2">
                    <!-- Toggle Active -->
                    <button
                      @click="toggleConnection(connection)"
                      :class="[
                        'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                        connection.is_active ? 'bg-blue-600' : 'bg-gray-200 dark:bg-gray-700'
                      ]"
                    >
                      <span
                        :class="[
                          'inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
                          connection.is_active ? 'translate-x-6' : 'translate-x-1'
                        ]"
                      />
                    </button>

                    <!-- Sync Button -->
                    <button
                      @click="syncConnection(connection)"
                      class="inline-flex items-center px-3 py-1.5 border border-gray-300 dark:border-gray-600 rounded-md text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700"
                    >
                      <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                      </svg>
                      Sync
                    </button>

                    <!-- Delete Button -->
                    <button
                      @click="deleteConnection(connection)"
                      class="inline-flex items-center px-3 py-1.5 border border-red-300 dark:border-red-600 rounded-md text-sm font-medium text-red-700 dark:text-red-400 bg-white dark:bg-gray-800 hover:bg-red-50 dark:hover:bg-red-900/20"
                    >
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                      </svg>
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Empty State -->
            <div v-else class="text-center py-12">
              <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
              <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">No calendar connections</h3>
              <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Get started by connecting your Google Calendar.
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineProps({
  connections: {
    type: Array,
    default: () => [],
  },
});

function toggleConnection(connection) {
  router.post(route('calendar.connections.toggle', connection.id), {}, {
    preserveScroll: true,
  });
}

function syncConnection(connection) {
  router.post(route('calendar.connections.sync', connection.id), {}, {
    preserveScroll: true,
  });
}

function deleteConnection(connection) {
  if (confirm(`Are you sure you want to remove the connection to "${connection.calendar_name}"?`)) {
    router.delete(route('calendar.connections.destroy', connection.id), {
      preserveScroll: true,
    });
  }
}
</script>

