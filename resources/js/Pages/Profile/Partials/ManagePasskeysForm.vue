<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import { useToast } from '@/composables/useToast';

const props = defineProps({
  passkeys: {
    type: Array,
    required: true,
  },
});

const toast = useToast();

// Edit state
const editingPasskeyId = ref(null);
const editForm = useForm({
  name: '',
});

// Delete state
const showDeleteModal = ref(false);
const passkeyToDelete = ref(null);

// Start editing a passkey name
const startEdit = (passkey) => {
  editingPasskeyId.value = passkey.id;
  editForm.name = passkey.name;
};

// Cancel editing
const cancelEdit = () => {
  editingPasskeyId.value = null;
  editForm.reset();
};

// Save passkey name
const savePasskeyName = (passkeyId) => {
  editForm.patch(route('passkeys.update', passkeyId), {
    preserveScroll: true,
    onSuccess: () => {
      editingPasskeyId.value = null;
      editForm.reset();
      toast.success('Passkey name updated successfully');
    },
    onError: () => {
      toast.error('Failed to update passkey name');
    },
  });
};

// Confirm delete
const confirmDelete = (passkey) => {
  passkeyToDelete.value = passkey;
  showDeleteModal.value = true;
};

// Delete passkey
const deletePasskey = () => {
  if (!passkeyToDelete.value) return;

  router.delete(route('passkeys.destroy', passkeyToDelete.value.id), {
    preserveScroll: true,
    onSuccess: () => {
      showDeleteModal.value = false;
      passkeyToDelete.value = null;
      toast.success('Passkey deleted successfully');
    },
    onError: (errors) => {
      showDeleteModal.value = false;
      passkeyToDelete.value = null;
      const errorMessage = errors.passkey || 'Failed to delete passkey';
      toast.error(errorMessage);
    },
  });
};

// Format date
const formatDate = (dateString) => {
  const date = new Date(dateString);
  return date.toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  });
};

// Format relative time
const formatRelativeTime = (dateString) => {
  const date = new Date(dateString);
  const now = new Date();
  const diffInSeconds = Math.floor((now - date) / 1000);

  if (diffInSeconds < 60) return 'Just now';
  if (diffInSeconds < 3600) return `${Math.floor(diffInSeconds / 60)} minutes ago`;
  if (diffInSeconds < 86400) return `${Math.floor(diffInSeconds / 3600)} hours ago`;
  if (diffInSeconds < 604800) return `${Math.floor(diffInSeconds / 86400)} days ago`;

  return formatDate(dateString);
};
</script>

<template>
  <section>
    <header>
      <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Passkeys</h2>
      <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
        Passkeys are a secure, passwordless way to sign in. They use cryptographic keys stored on your devices.
      </p>
    </header>

    <!-- Add Passkey Button -->
    <div class="mt-6">
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

    <!-- Passkeys List -->
    <div v-if="passkeys.length > 0" class="mt-6 space-y-4">
      <div
        v-for="passkey in passkeys"
        :key="passkey.id"
        class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:border-gray-300 dark:hover:border-gray-600 transition-colors"
      >
        <div class="flex items-start justify-between">
          <div class="flex-1">
            <div class="flex items-center">
              <!-- Passkey Icon -->
              <div class="flex-shrink-0">
                <svg class="h-8 w-8 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                </svg>
              </div>

              <!-- Passkey Info -->
              <div class="ml-4 flex-1">
                <!-- Editable Name -->
                <div v-if="editingPasskeyId === passkey.id" class="flex items-center gap-2">
                  <input
                    v-model="editForm.name"
                    type="text"
                    class="block w-full max-w-md rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                    @keyup.enter="savePasskeyName(passkey.id)"
                    @keyup.escape="cancelEdit"
                  />
                  <button
                    @click="savePasskeyName(passkey.id)"
                    class="inline-flex items-center px-3 py-1 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700"
                  >
                    Save
                  </button>
                  <button
                    @click="cancelEdit"
                    class="inline-flex items-center px-3 py-1 border border-gray-300 dark:border-gray-600 text-sm font-medium rounded-md text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700"
                  >
                    Cancel
                  </button>
                </div>

                <!-- Display Name -->
                <div v-else class="flex items-center gap-2">
                  <h4 class="text-base font-medium text-gray-900 dark:text-gray-100">
                    {{ passkey.name }}
                  </h4>
                  <button
                    @click="startEdit(passkey)"
                    class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                    title="Rename passkey"
                  >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                    </svg>
                  </button>
                </div>

                <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                  <p>Created: {{ formatDate(passkey.created_at) }}</p>
                  <p>Last used: {{ formatRelativeTime(passkey.last_used) }}</p>
                </div>
              </div>
            </div>
          </div>

          <!-- Delete Button -->
          <div class="ml-4">
            <button
              @click="confirmDelete(passkey)"
              class="inline-flex items-center px-3 py-2 border border-red-300 dark:border-red-700 text-sm font-medium rounded-md text-red-700 dark:text-red-400 bg-white dark:bg-gray-800 hover:bg-red-50 dark:hover:bg-red-900/20 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
            >
              <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
              </svg>
              Delete
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else class="mt-6 text-center py-12 border border-dashed border-gray-300 dark:border-gray-700 rounded-lg">
      <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
      </svg>
      <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">No passkeys</h3>
      <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Get started by adding your first passkey.</p>
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
          <h3 class="text-sm font-medium text-blue-800 dark:text-blue-300">About Passkeys</h3>
          <div class="mt-2 text-sm text-blue-700 dark:text-blue-400">
            <ul class="list-disc pl-5 space-y-1">
              <li>Passkeys are stored securely on your device (e.g., iCloud Keychain, Google Password Manager)</li>
              <li>Deleting a passkey here removes it from our server, but you may need to remove it from your device's password manager separately</li>
              <li>You can add multiple passkeys for different devices</li>
              <li>Passkeys are more secure than passwords and can't be phished</li>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <Modal :show="showDeleteModal" @close="showDeleteModal = false">
      <div class="p-6">
        <div class="flex items-start">
          <div class="flex-shrink-0">
            <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
          </div>
          <div class="ml-3 flex-1">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Delete Passkey</h3>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
              Are you sure you want to delete "{{ passkeyToDelete?.name }}"? You won't be able to use this passkey to sign in anymore.
            </p>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
              Remember to also remove it from your device's password manager if needed.
            </p>
          </div>
        </div>
        <div class="mt-5 flex justify-end gap-3">
          <button
            @click="showDeleteModal = false"
            class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
          >
            Cancel
          </button>
          <button
            @click="deletePasskey"
            class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
          >
            Delete Passkey
          </button>
        </div>
      </div>
    </Modal>
  </section>
</template>

