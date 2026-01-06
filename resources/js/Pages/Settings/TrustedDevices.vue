<template>
  <Head title="Trusted Devices" />

  <AuthenticatedLayout>
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 leading-tight">Trusted Devices</h2>
    </template>

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <!-- Header Section -->
            <div class="mb-6">
              <h3 class="text-lg font-medium text-gray-900">Manage Your Devices</h3>
              <p class="mt-1 text-sm text-gray-600">
                These devices are remembered and can automatically log you in. Devices authenticated with passkeys provide the highest security. Remove any devices you don't recognize.
              </p>
            </div>

            <!-- Success Message -->
            <div v-if="$page.props.flash?.message" class="mb-6 rounded-md bg-green-50 p-4">
              <div class="flex">
                <div class="flex-shrink-0">
                  <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                  </svg>
                </div>
                <div class="ml-3">
                  <p class="text-sm font-medium text-green-800">{{ $page.props.flash.message }}</p>
                </div>
              </div>
            </div>

            <!-- Actions Bar -->
            <div class="mb-6 flex justify-between items-center">
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
                  class="inline-flex items-center px-4 py-2 border border-red-300 text-sm font-medium rounded-md text-red-700 bg-white hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                >
                  <svg class="h-5 w-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                  </svg>
                  Revoke All Devices
                </button>
              </div>
            </div>

            <!-- Devices List -->
            <div v-if="trustedDevices.length > 0" class="space-y-4">
              <div
                v-for="device in trustedDevices"
                :key="device.id"
                class="border border-gray-200 rounded-lg p-4 hover:border-gray-300 transition-colors"
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
                        <div class="flex items-center gap-2">
                          <h4 class="text-base font-medium text-gray-900">
                            {{ device.device_name || 'Unknown Device' }}
                          </h4>
                          <span 
                            v-if="device.auth_method === 'passkey'"
                            class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-800"
                          >
                            <svg class="h-3 w-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                            </svg>
                            Passkey
                          </span>
                          <span 
                            v-else-if="device.auth_method === 'magic_link'"
                            class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800"
                          >
                            <svg class="h-3 w-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                            Magic Link
                          </span>
                        </div>
                        <div class="mt-1 space-y-1">
                          <p class="text-sm text-gray-500">
                            <span class="font-medium">IP Address:</span> {{ device.ip_address || 'Unknown' }}
                          </p>
                          <p class="text-sm text-gray-500">
                            <span class="font-medium">Last used:</span> {{ formatDate(device.last_used_at) }}
                          </p>
                          <p class="text-sm text-gray-500">
                            <span class="font-medium">Expires:</span> {{ formatDate(device.expires_at) }}
                          </p>
                          <p v-if="isCurrentDevice(device)" class="text-sm text-green-600 font-medium">
                            • This device
                          </p>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Actions -->
                  <div class="ml-4">
                    <button
                      @click="confirmRevoke(device)"
                      class="inline-flex items-center px-3 py-2 border border-red-300 text-sm font-medium rounded-md text-red-700 bg-white hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
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
            <div v-else class="text-center py-12">
              <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
              </svg>
              <h3 class="mt-2 text-sm font-medium text-gray-900">No trusted devices</h3>
              <p class="mt-1 text-sm text-gray-500">
                Register a passkey to get started.
              </p>
              <div class="mt-6">
                <Link
                  :href="route('passkey.register')"
                  class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                >
                  <svg class="h-5 w-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                  </svg>
                  Register Passkey
                </Link>
              </div>
            </div>

            <!-- Info Box -->
            <div class="mt-6 rounded-md bg-blue-50 p-4">
              <div class="flex">
                <div class="flex-shrink-0">
                  <svg class="h-5 w-5 text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                  </svg>
                </div>
                <div class="ml-3">
                  <h3 class="text-sm font-medium text-blue-800">About Trusted Devices</h3>
                  <div class="mt-2 text-sm text-blue-700">
                    <ul class="list-disc pl-5 space-y-1">
                      <li><strong>Passkey devices</strong> are authenticated using biometric security (Face ID, Touch ID, etc.)</li>
                      <li><strong>Magic Link devices</strong> are authenticated via email link</li>
                      <li>Trusted devices can automatically log you in for 90 days</li>
                      <li>You'll receive an email notification when a new device is added</li>
                      <li>Revoke any device you don't recognize immediately</li>
                    </ul>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Revoke Single Device Modal -->
    <Modal :show="showRevokeModal" @close="showRevokeModal = false">
      <div class="p-6">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Revoke Device Access</h3>
        <p class="text-sm text-gray-600 mb-6">
          Are you sure you want to revoke access for <strong>{{ deviceToRevoke?.device_name }}</strong>? 
          This device will need to authenticate again to access your account.
        </p>
        <div class="flex justify-end space-x-3">
          <button
            @click="showRevokeModal = false"
            class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
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
        <h3 class="text-lg font-medium text-gray-900 mb-4">Revoke All Devices</h3>
        <p class="text-sm text-gray-600 mb-6">
          Are you sure you want to revoke access for <strong>all devices</strong>? 
          All devices (including this one) will need to authenticate again to access your account.
        </p>
        <div class="flex justify-end space-x-3">
          <button
            @click="showRevokeAllModal = false"
            class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
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
  </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
  trustedDevices: Array,
});

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
  // Check if this is the most recently used device
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
    },
  });
};

const revokeAllDevices = () => {
  router.post(route('trusted-devices.revoke-all'), {}, {
    preserveScroll: true,
    onSuccess: () => {
      showRevokeAllModal.value = false;
    },
  });
};
</script>

