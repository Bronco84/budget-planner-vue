<template>
    <div class="space-y-4">
        <!-- Header -->
        <div class="flex justify-between items-center">
            <h3 class="text-lg font-medium text-gray-900">Attached Files</h3>
            <span class="text-sm text-gray-500">{{ attachments.length }} file{{ attachments.length !== 1 ? 's' : '' }}</span>
        </div>

        <!-- Empty State -->
        <div v-if="attachments.length === 0" class="text-center py-8">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <p class="mt-2 text-sm text-gray-500">No files attached yet</p>
        </div>

        <!-- File List -->
        <div v-else class="space-y-3">
            <div
                v-for="attachment in attachments"
                :key="attachment.id"
                class="flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200"
            >
                <div class="flex items-center space-x-3">
                    <!-- File Icon -->
                    <div class="flex-shrink-0">
                        <svg class="h-8 w-8 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="getFileIconPath(attachment.file.mime_type)"/>
                        </svg>
                    </div>

                    <!-- File Info -->
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 truncate">
                            {{ attachment.file.original_name }}
                        </p>
                        <div class="flex items-center space-x-2 text-xs text-gray-500">
                            <span>{{ attachment.file.formatted_size }}</span>
                            <span>•</span>
                            <span>{{ attachment.attached_by }}</span>
                            <span>•</span>
                            <span>{{ formatDate(attachment.created_at) }}</span>
                        </div>
                        <p v-if="attachment.description" class="text-xs text-gray-600 mt-1">
                            {{ attachment.description }}
                        </p>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex items-center space-x-2">
                    <!-- Download Button -->
                    <button
                        type="button"
                        @click="downloadFile(attachment)"
                        class="inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-xs font-medium rounded text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                        :disabled="downloading === attachment.id"
                    >
                        <svg v-if="downloading === attachment.id" class="animate-spin -ml-1 mr-2 h-3 w-3 text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <svg v-else class="-ml-1 mr-2 h-3 w-3 text-gray-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        Download
                    </button>

                    <!-- Delete Button -->
                    <button
                        type="button"
                        @click="confirmDelete(attachment)"
                        class="inline-flex items-center px-3 py-1.5 border border-red-300 shadow-sm text-xs font-medium rounded text-red-700 bg-white hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                        :disabled="deleting === attachment.id"
                    >
                        <svg v-if="deleting === attachment.id" class="animate-spin -ml-1 mr-2 h-3 w-3 text-red-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <svg v-else class="-ml-1 mr-2 h-3 w-3 text-red-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                        Delete
                    </button>
                </div>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <Modal :show="showDeleteModal" @close="showDeleteModal = false">
            <div class="p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <svg class="h-6 w-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.864-.833-2.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                        </svg>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-medium text-gray-900">Delete File</h3>
                        <p class="mt-2 text-sm text-gray-500">
                            Are you sure you want to delete "{{ fileToDelete?.file.original_name }}"? This action cannot be undone.
                        </p>
                    </div>
                </div>

                <div class="mt-6 flex justify-end space-x-3">
                    <SecondaryButton @click="showDeleteModal = false">
                        Cancel
                    </SecondaryButton>
                    <DangerButton @click="deleteFile" :disabled="deleting === fileToDelete?.id">
                        <svg v-if="deleting === fileToDelete?.id" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Delete
                    </DangerButton>
                </div>
            </div>
        </Modal>

        <!-- Error Messages -->
        <div v-if="error" class="rounded-md bg-red-50 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-red-800">{{ error }}</p>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import Modal from '@/Components/Modal.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'

const props = defineProps({
    attachments: {
        type: Array,
        required: true
    }
})

const emit = defineEmits(['deleted', 'error'])

const downloading = ref(null)
const deleting = ref(null)
const showDeleteModal = ref(false)
const fileToDelete = ref(null)
const error = ref('')

const downloadFile = async (attachment) => {
    downloading.value = attachment.id
    error.value = ''
    
    try {
        // Fetch file as blob to prevent page redirects and stay on current page
        const response = await fetch(`/files/${attachment.id}/download`, {
            method: 'GET',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            }
        })
        
        if (!response.ok) {
            throw new Error('Download failed')
        }
        
        const blob = await response.blob()
        const url = window.URL.createObjectURL(blob)
        
        // Create temporary link to trigger download without navigation
        const link = document.createElement('a')
        link.href = url
        link.download = attachment.file.original_name.replace(/[<>:"/\\|?*\x00-\x1F]/g, '_')
        link.style.display = 'none'
        document.body.appendChild(link)
        link.click()
        document.body.removeChild(link)
        
        // Clean up the blob URL
        window.URL.revokeObjectURL(url)
        
    } catch (err) {
        error.value = err.message || 'Download failed'
    } finally {
        downloading.value = null
    }
}



const confirmDelete = (attachment) => {
    fileToDelete.value = attachment
    showDeleteModal.value = true
}

const deleteFile = async () => {
    if (!fileToDelete.value) return
    
    deleting.value = fileToDelete.value.id
    error.value = ''
    
    try {
        const response = await fetch(`/files/${fileToDelete.value.id}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json'
            }
        })
        
        if (!response.ok) {
            const errorData = await response.json()
            throw new Error(errorData.error || 'Delete failed')
        }
        
        emit('deleted', fileToDelete.value.id)
        showDeleteModal.value = false
        fileToDelete.value = null
        
    } catch (err) {
        error.value = err.message || 'Delete failed'
    } finally {
        deleting.value = null
    }
}

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    })
}

const getFileIconPath = (mimeType) => {
    if (mimeType.startsWith('image/')) {
        return 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z'
    }
    
    if (mimeType === 'application/pdf') {
        return 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'
    }
    
    if (mimeType.includes('spreadsheet') || mimeType.includes('excel') || mimeType === 'text/csv') {
        return 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2 2zM3 9h18M9 21V9M15 21V9'
    }
    
    if (mimeType.includes('document') || mimeType.includes('word') || mimeType === 'text/plain') {
        return 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'
    }
    
    // Default file icon
    return 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'
}
</script>

 