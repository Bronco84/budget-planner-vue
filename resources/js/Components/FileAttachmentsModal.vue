<template>
  <Modal :show="show" @close="$emit('close')" max-width="2xl">
    <div class="p-6">
      <h2 class="text-lg font-medium text-gray-900 mb-4">
        Budget File Attachments
      </h2>

      <div class="space-y-4">
        <div v-if="attachments.length === 0" class="text-center py-8 text-gray-500">
          <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
          </svg>
          <p class="mt-2">No files attached</p>
        </div>

        <div v-else>
          <FileAttachmentList
            :attachments="attachments"
            @deleted="handleFileDeleted"
          />
        </div>

        <div class="pt-4 border-t border-gray-200">
          <button
            @click="$emit('upload')"
            class="w-full inline-flex justify-center items-center px-4 py-2 border border-indigo-300 text-sm font-medium rounded-md text-indigo-700 bg-indigo-50 hover:bg-indigo-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
          >
            <svg class="h-5 w-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Attach File
          </button>
        </div>
      </div>

      <div class="mt-6 flex justify-end">
        <button
          @click="$emit('close')"
          type="button"
          class="inline-flex justify-center px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
        >
          Close
        </button>
      </div>
    </div>
  </Modal>
</template>

<script setup>
import Modal from '@/Components/Modal.vue';
import FileAttachmentList from '@/Components/FileAttachmentList.vue';

defineProps({
  show: Boolean,
  attachments: {
    type: Array,
    default: () => []
  }
});

const emit = defineEmits(['close', 'upload', 'deleted']);

const handleFileDeleted = (fileId) => {
  emit('deleted', fileId);
};
</script>




