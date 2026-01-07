<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import { useToast } from '@/composables/useToast';

const props = defineProps({
  trustedDevices: Array,
});

const toast = useToast();
const showRevokeModal = ref(false);
const showRevokeAllModal = ref(false);
const deviceToRevoke = ref(null);

const formatDate = (dateString) => {
  if (!dateString) return 'Unknown';
  const date = new Date(dateString);
  return date.toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
};

const isCurrentDevice = (device) => {
  if (props.trustedDevices.length === 0) return false;
  const mostRecent = props.trustedDevices.reduce((prev, current) => {
    return new Date(current.last_used_at) > new Date(prev.last_used_at) ? current : prev;
  });
  return device.id === mostRecent.id;
};

const confirmRevoke = (device) => {
  deviceToRevoke.value = device;
  showRevokeModal.value = true;
};

const revokeDevice = () => {
  if (!deviceToRevoke.value) return;

  router.delete(route('trusted-devices.revoke', deviceToRevoke.value.id), {
    preserveScroll: true,
    onSuccess: () => {
      showRevokeModal.value = false;
      deviceToRevoke.value = null;
      toast.success('Device revoked successfully');
    },
    onError: () => {
      showRevokeModal.value = false;
      deviceToRevoke.value = null;
      toast.error('Failed to revoke device');
    },
  });
};

const revokeAllDevices = () => {
  router.post(route('trusted-devices.revoke-all'), {}, {
    preserveScroll: true,
    onSuccess: () => {
      showRevokeAllModal.value = false;
      toast.success('All devices revoked successfully');
    },
    onError: () => {
      showRevokeAllModal.value = false;
      toast.error('Failed to revoke all devices');
    },
  });
};
</script>

<template>
  <section>
    <header>
      <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Trusted Devices</h2>
      <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
        These devices are remembered and can automatically log you in. Remove any devices you don't recognize.
      </p>
    </header>

    <!-- Actions Bar -->
    <div class="mt-6 flex justify-between items-center flex-wrap gap-4">
      <div>
        <Link
          :href="route('passkey.register')"
          class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
        >
          <svg class="h-5 w-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          Add New Passkey
        </Link>
      </div>
      <div>
        <button
          v-if="trustedDevices.length > 0"
          @click="showRevokeAllModal = true"
          class="inline-flex items-center px-4 py-2 border border-red-300 dark:border-red-700 text-sm font-medium rounded-md text-red-700 dark:text-red-400 bg-white dark:bg-gray-800 hover:bg-red-50 dark:hover:bg-red-900/20 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
        >
          <svg class="h-5 w-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
          </svg>
          Revoke All Devices
        </button>
      </div>
    </div>

    <!-- Devices List -->
    <div v-if="trustedDevices.length > 0" class="mt-6 space-y-4">
      <div
        v-for="device in trustedDevices"
        :key="device.id"
        class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:border-gray-300 dark:hover:border-gray-600 transition-colors"
      >
        <div class="flex items-start justify-between">
          <div class="flex-1">
            <div class="flex items-center">
              <!-- Device Icon -->
              <div class="flex-shrink-0">
                <svg class="h-8 w-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                </svg>
              </div>
              
              <!-- Device Info -->
              <div class="ml-4">
                <div class="flex items-center gap-2 flex-wrap">
                  <h4 class="text-base font-medium text-gray-900 dark:text-gray-100">
                    {{ device.device_name || 'Unknown Device' }}
                  </h4>
                  <span 
                    v-if="device.auth_method === 'passkey'"
                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 dark:bg-indigo-900/30 text-indigo-800 dark:text-indigo-300"
                  >
                    <svg class="h-3 w-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                    </svg>
                    Passkey
                  </span>
                  <span 
                    v-else-if="device.auth_method === 'magic_link'"
                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 dark:bg-purple-900/30 text-purple-800 dark:text-purple-300"
                  >
                    <svg class="h-3 w-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                    Magic Link
                  </span>
                  <span 
                    v-if="isCurrentDevice(device)"
                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300"
                  >
                    <svg class="h-3 w-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    Current Device
                  </span>
                </div>
                <div class="mt-2 text-sm text-gray-500 dark:text-gray-400 space-y-1">
                  <p><span class="font-medium">Browser:</span> {{ device.browser || 'Unknown' }}</p>
                  <p><span class="font-medium">Platform:</span> {{ device.platform || 'Unknown' }}</p>
                  <p><span class="font-medium">IP Address:</span> {{ device.ip_address || 'Unknown' }}</p>
                  <p><span class="font-medium">Last used:</span> {{ formatDate(device.last_used_at) }}</p>
                  <p><span class="font-medium">Created:</span> {{ formatDate(device.created_at) }}</p>
                </div>
              </div>
            </div>
          </div>

          <!-- Revoke Button -->
          <div class="ml-4">
            <button
              @click="confirmRevoke(device)"
              class="inline-flex items-center px-3 py-2 border border-red-300 dark:border-red-700 text-sm font-medium rounded-md text-red-700 dark:text-red-400 bg-white dark:bg-gray-800 hover:bg-red-50 dark:hover:bg-red-900/20 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
            >
              <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
              Revoke
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else class="mt-6 text-center py-12 border border-dashed border-gray-300 dark:border-gray-700 rounded-lg">
      <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
      </svg>
      <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">No trusted devices</h3>
      <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Add a passkey to get started.</p>
    </div>

    <!-- Info Box -->
    <div class="mt-6 rounded-md bg-blue-50 dark:bg-blue-900/20 p-4">
      <div class="flex">
        <div class="flex-shrink-0">
          <svg class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
          </svg>
        </div>
        <div class="ml-3 flex-1">
          <h3 class="text-sm font-medium text-blue-800 dark:text-blue-300">About Trusted Devices</h3>
          <div class="mt-2 text-sm text-blue-700 dark:text-blue-400">
            <ul class="list-disc pl-5 space-y-1">
              <li>Devices authenticated with passkeys provide the highest security</li>
              <li>Revoking a device will require it to authenticate again</li>
              <li>Regularly review your trusted devices for security</li>
              <li>Remove any devices you don't recognize immediately</li>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <!-- Revoke Device Modal -->
    <Modal :show="showRevokeModal" @close="showRevokeModal = false">
      <div class="p-6">
        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Revoke Device Access</h3>
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">
          Are you sure you want to revoke access for <strong>{{ deviceToRevoke?.device_name || 'this device' }}</strong>? 
          This device will need to authenticate again to access your account.
        </p>
        <div class="flex justify-end space-x-3">
          <button
            @click="showRevokeModal = false"
            class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
          >
            Cancel
          </button>
          <button
            @click="revokeDevice"
            class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
          >
            Revoke Device
          </button>
        </div>
      </div>
    </Modal>

    <!-- Revoke All Devices Modal -->
    <Modal :show="showRevokeAllModal" @close="showRevokeAllModal = false">
      <div class="p-6">
        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Revoke All Devices</h3>
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">
          Are you sure you want to revoke access for <strong>all devices</strong>? 
          All devices (including this one) will need to authenticate again to access your account.
        </p>
        <div class="flex justify-end space-x-3">
          <button
            @click="showRevokeAllModal = false"
            class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
          >
            Cancel
          </button>
          <button
            @click="revokeAllDevices"
            class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
          >
            Revoke All Devices
          </button>
        </div>
      </div>
    </Modal>
  </section>
</template>

