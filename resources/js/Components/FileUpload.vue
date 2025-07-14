<template>
    <div class="space-y-4">
        <!-- File Upload Area -->
        <div
            @drop.prevent="handleDrop"
            @dragover.prevent
            @dragenter.prevent
            @dragleave.prevent
            :class="[
                'border-2 border-dashed rounded-lg p-6 text-center transition-colors',
                dragOver ? 'border-blue-400 bg-blue-50' : 'border-gray-300',
                uploading ? 'opacity-50 cursor-not-allowed' : 'hover:border-gray-400'
            ]"
        >
            <div v-if="!uploading" class="space-y-2">
                <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                    <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <div class="text-sm text-gray-600">
                    <label for="file-upload" class="cursor-pointer font-medium text-indigo-600 hover:text-indigo-500">
                        Upload a file
                    </label>
                    <span> or drag and drop</span>
                </div>
                <p class="text-xs text-gray-500">
                    PDF, DOC, XLS, Images up to 10MB
                </p>
                <input
                    id="file-upload"
                    type="file"
                    class="sr-only"
                    @change="handleFileSelect"
                    :accept="acceptedTypes"
                    :disabled="uploading"
                />
            </div>
            
            <!-- Upload Progress -->
            <div v-if="uploading" class="space-y-2">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
                <p class="text-sm text-gray-600">Uploading...</p>
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div 
                        class="bg-indigo-600 h-2 rounded-full transition-all duration-300"
                        :style="{ width: `${uploadProgress}%` }"
                    ></div>
                </div>
                <p class="text-xs text-gray-500">{{ uploadProgress }}%</p>
            </div>
        </div>

        <!-- Description Field -->
        <div v-if="showDescription">
            <InputLabel for="file-description" value="Description (optional)" />
            <TextInput
                id="file-description"
                type="text"
                class="mt-1 block w-full"
                v-model="description"
                placeholder="Add a description for this file..."
                :disabled="uploading"
            />
        </div>

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

        <!-- Success Message -->
        <div v-if="success" class="rounded-md bg-green-50 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-green-800">{{ success }}</p>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import InputLabel from '@/Components/InputLabel.vue'
import TextInput from '@/Components/TextInput.vue'

const props = defineProps({
    uploadUrl: {
        type: String,
        required: true
    },
    showDescription: {
        type: Boolean,
        default: true
    },
    acceptedTypes: {
        type: String,
        default: '.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.json,.jpg,.jpeg,.png,.gif,.webp'
    },
    maxSize: {
        type: Number,
        default: 10 * 1024 * 1024 // 10MB
    }
})

const emit = defineEmits(['uploaded', 'error'])

const dragOver = ref(false)
const uploading = ref(false)
const uploadProgress = ref(0)
const description = ref('')
const error = ref('')
const success = ref('')

const handleDrop = (e) => {
    dragOver.value = false
    const files = Array.from(e.dataTransfer.files)
    if (files.length > 0) {
        processFile(files[0])
    }
}

const handleFileSelect = (e) => {
    const files = Array.from(e.target.files)
    if (files.length > 0) {
        processFile(files[0])
    }
}

const processFile = (file) => {
    // Reset states
    error.value = ''
    success.value = ''
    
    // Validate file size
    if (file.size > props.maxSize) {
        error.value = 'File size exceeds 10MB limit'
        return
    }
    
    // Validate file type
    const validTypes = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'text/plain',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/json',
        'text/csv'
    ]
    
    if (!validTypes.includes(file.type)) {
        error.value = 'File type not supported'
        return
    }
    
    uploadFile(file)
}

const uploadFile = async (file) => {
    uploading.value = true
    uploadProgress.value = 0
    
    const formData = new FormData()
    formData.append('file', file)
    if (description.value) {
        formData.append('description', description.value)
    }
    
    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (!csrfToken) {
            throw new Error('CSRF token not found');
        }
        
        const response = await fetch(props.uploadUrl, {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            }
        })
        
        if (!response.ok) {
            const errorData = await response.json()
            throw new Error(errorData.error || 'Upload failed')
        }
        
        const data = await response.json()
        uploadProgress.value = 100
        
        setTimeout(() => {
            success.value = 'File uploaded successfully!'
            description.value = ''
            uploading.value = false
            uploadProgress.value = 0
            emit('uploaded', data.attachment)
            
            // Clear success message after 3 seconds
            setTimeout(() => {
                success.value = ''
            }, 3000)
        }, 500)
        
    } catch (err) {
        error.value = err.message || 'Upload failed'
        uploading.value = false
        uploadProgress.value = 0
        emit('error', err.message)
    }
}

// Simulate progress for better UX
const simulateProgress = () => {
    const interval = setInterval(() => {
        if (uploadProgress.value < 90) {
            uploadProgress.value += Math.random() * 10
        } else {
            clearInterval(interval)
        }
    }, 200)
}
</script> 