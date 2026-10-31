<template>
  <Modal :show="show" max-width="2xl" @close="$emit('close')">
    <div class="p-6">
      <h2 class="text-lg font-medium text-gray-900">Receipts &amp; attachments</h2>
      <p v-if="transaction" class="mt-1 text-sm text-gray-500 truncate">
        {{ transaction.description }}
      </p>

      <div class="mt-4">
        <div v-if="loading" class="py-8 text-center text-sm text-gray-500">
          Loading attachments...
        </div>

        <div v-else-if="error" class="rounded-md bg-red-50 p-4">
          <p class="text-sm font-medium text-red-800">{{ error }}</p>
        </div>

        <FileAttachmentList
          v-else
          :attachments="attachments"
          readonly
        />
      </div>

      <div class="mt-6 flex justify-end">
        <SecondaryButton @click="$emit('close')">Close</SecondaryButton>
      </div>
    </div>
  </Modal>
</template>

<script setup>
import { ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import FileAttachmentList from '@/Components/FileAttachmentList.vue';
import { apiFetch } from '@/utils/http.js';

/**
 * Read-only list of a transaction's attachments (name, size, uploader, date)
 * with download buttons. Usable by any budget member, including viewers;
 * uploading and deleting stay on the transaction edit page.
 */
const props = defineProps({
  show: Boolean,
  transaction: {
    type: Object,
    default: null,
  },
});

defineEmits(['close']);

const attachments = ref([]);
const loading = ref(false);
const error = ref('');

async function load(transactionId) {
  loading.value = true;
  error.value = '';
  attachments.value = [];

  try {
    const response = await apiFetch(route('transactions.files.index', transactionId), { method: 'GET' });

    if (!response.ok) {
      throw new Error('Could not load attachments.');
    }

    const data = await response.json();
    attachments.value = data.attachments || [];
  } catch (err) {
    error.value = err.message || 'Could not load attachments.';
  } finally {
    loading.value = false;
  }
}

watch(
  () => [props.show, props.transaction?.id],
  ([show, id]) => {
    if (show && id) {
      load(id);
    }
  },
  { immediate: true },
);
</script>
