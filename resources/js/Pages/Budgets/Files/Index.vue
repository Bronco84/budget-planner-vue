<template>
  <Head title="Files" />

  <AuthenticatedLayout>
    <div class="py-6">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="mb-6">
          <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">File Manager</h1>
          <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
            Manage files attached to {{ budget.name }}
          </p>
        </div>

        <!-- Upload Section -->
        <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg mb-6">
          <div class="p-6">
            <div class="flex items-center justify-between mb-4">
              <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Upload Files</h2>
              <button
                @click="showUploadModal = true"
                class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:border-indigo-900 focus:ring ring-indigo-300 disabled:opacity-25 transition ease-in-out duration-150"
              >
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Upload File
              </button>
            </div>

            <div class="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-12 text-center">
              <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
              </svg>
              <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                Click "Upload File" to add documents, images, or other files
              </p>
              <p class="mt-1 text-xs text-gray-500 dark:text-gray-500">
                Supported formats: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, and more
              </p>
            </div>
          </div>
        </div>

        <!-- Files List -->
        <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
          <div class="p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
              Attached Files
              <span class="ml-2 text-sm font-normal text-gray-500">({{ files.length }})</span>
            </h2>

            <div v-if="files.length === 0" class="text-center py-12">
              <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
              <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">No files attached yet</p>
              <p class="mt-1 text-xs text-gray-500">Upload your first file to get started</p>
            </div>

            <div v-else class="space-y-3">
              <div
                v-for="file in files"
                :key="file.id"
                class="flex items-center justify-between p-4 border border-gray-200 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
              >
                <div class="flex items-center min-w-0 flex-1">
                  <!-- File Icon -->
                  <div class="flex-shrink-0">
                    <svg v-if="isImageFile(file)" class="h-10 w-10 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    <svg v-else-if="isPdfFile(file)" class="h-10 w-10 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                    </svg>
                    <svg v-else class="h-10 w-10 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                  </div>

                  <!-- File Info -->
                  <div class="ml-4 min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">
                      {{ file.original_filename }}
                    </p>
                    <div class="flex items-center mt-1 text-xs text-gray-500 dark:text-gray-400 space-x-3">
                      <span>{{ formatFileSize(file.size) }}</span>
                      <span>•</span>
                      <span>{{ formatDate(file.created_at) }}</span>
                      <span v-if="file.mime_type">•</span>
                      <span v-if="file.mime_type">{{ file.mime_type }}</span>
                    </div>
                  </div>
                </div>

                <!-- Actions -->
                <div class="flex items-center space-x-2 ml-4 flex-shrink-0">
                  <a
                    :href="`/budgets/${budget.id}/files/${file.id}/download`"
                    class="inline-flex items-center px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                    title="Download"
                  >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                  </a>
                  <button
                    @click="confirmDelete(file)"
                    class="inline-flex items-center px-3 py-2 border border-red-300 dark:border-red-600 rounded-md text-sm font-medium text-red-700 dark:text-red-400 bg-white dark:bg-gray-700 hover:bg-red-50 dark:hover:bg-red-900/20 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                    title="Delete"
                  >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Upload Modal -->
    <Modal :show="showUploadModal" @close="showUploadModal = false">
      <div class="p-6">
        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Upload File</h3>
        <FileUpload
          :upload-url="`/budgets/${budget.id}/files`"
          @uploaded="handleFileUploaded"
          @error="handleFileError"
        />
      </div>
    </Modal>

    <!-- Delete Confirmation Modal -->
    <Modal :show="showDeleteModal" @close="showDeleteModal = false">
      <div class="p-6">
        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">Delete File</h3>
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">
          Are you sure you want to delete <strong>{{ fileToDelete?.original_filename }}</strong>? This action cannot be undone.
        </p>
        <div class="flex justify-end space-x-3">
          <button
            @click="showDeleteModal = false"
            class="inline-flex justify-center px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
          >
            Cancel
          </button>
          <button
            @click="deleteFile"
            class="inline-flex justify-center px-4 py-2 text-sm font-medium text-white bg-red-600 border border-transparent rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
          >
            Delete
          </button>
        </div>
      </div>
    </Modal>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import FileUpload from '@/Components/FileUpload.vue';

const props = defineProps({
  budget: Object,
  files: Array
});

const showUploadModal = ref(false);
const showDeleteModal = ref(false);
const fileToDelete = ref(null);

const handleFileUploaded = (file) => {
  showUploadModal.value = false;
  router.reload();
};

const handleFileError = (error) => {
  console.error('File upload error:', error);
};

const confirmDelete = (file) => {
  fileToDelete.value = file;
  showDeleteModal.value = true;
};

const deleteFile = () => {
  if (!fileToDelete.value) return;

  router.delete(`/budgets/${props.budget.id}/files/${fileToDelete.value.id}`, {
    onSuccess: () => {
      showDeleteModal.value = false;
      fileToDelete.value = null;
    }
  });
};

const formatFileSize = (bytes) => {
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
};

const formatDate = (dateString) => {
  const date = new Date(dateString);
  return date.toLocaleDateString('en-US', { 
    year: 'numeric', 
    month: 'short', 
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
};

const isImageFile = (file) => {
  return file.mime_type?.startsWith('image/');
};

const isPdfFile = (file) => {
  return file.mime_type === 'application/pdf';
};
</script>




