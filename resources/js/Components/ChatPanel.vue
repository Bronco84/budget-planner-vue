<script setup>
import { ref, computed, watch, nextTick, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import ChatMessage from './ChatMessage.vue';
import {
    XMarkIcon,
    PaperAirplaneIcon,
    PlusCircleIcon,
    TrashIcon,
    ChatBubbleLeftIcon
} from '@heroicons/vue/24/outline';
import axios from 'axios';

const props = defineProps({
    isOpen: {
        type: Boolean,
        default: false
    }
});

const emit = defineEmits(['close']);

const messages = ref([]);
const currentMessage = ref('');
const isLoading = ref(false);
const error = ref(null);
const currentConversationId = ref(null);
const conversations = ref([]);
const messagesContainerRef = ref(null);

// View management
const currentView = ref('chat'); // 'chat' or 'history'
const selectedConversations = ref([]);
const isSelectMode = ref(false);

// Resizing functionality
const panelWidth = ref(384); // Default width (w-96 = 384px)
const isResizing = ref(false);
const minWidth = 384; // Minimum width (w-96)
const maxWidth = 800; // Maximum width
const isMobile = computed(() => typeof window !== 'undefined' && window.innerWidth < 640);

// Load conversations on mount
onMounted(() => {
    // Load saved panel width from localStorage
    const savedWidth = localStorage.getItem('chatPanelWidth');
    if (savedWidth) {
        const width = parseInt(savedWidth);
        if (width >= minWidth && width <= maxWidth) {
            panelWidth.value = width;
        }
    }

    if (props.isOpen) {
        loadConversations();
    }
});

// Watch for panel opening
watch(() => props.isOpen, (newValue) => {
    if (newValue) {
        loadConversations();
    } else {
        // Reset state when closing
        error.value = null;
    }
});

// Auto-scroll to bottom when messages change
watch(messages, async () => {
    await nextTick();
    scrollToBottom();
}, { deep: true });

const scrollToBottom = () => {
    if (messagesContainerRef.value) {
        messagesContainerRef.value.scrollTop = messagesContainerRef.value.scrollHeight;
    }
};

const loadConversations = async () => {
    try {
        const response = await axios.get(route('chat.conversations'));
        conversations.value = response.data.conversations;
    } catch (err) {
        // Failed to load conversations
    }
};

const loadConversation = async (conversationId) => {
    try {
        isLoading.value = true;
        const response = await axios.get(route('chat.conversations.show', conversationId));
        messages.value = response.data.conversation.messages;
        currentConversationId.value = conversationId;
        currentView.value = 'chat'; // Switch to chat view
        error.value = null;
    } catch (err) {
        error.value = 'Failed to load conversation';
    } finally {
        isLoading.value = false;
    }
};

const sendMessage = async () => {
    if (!currentMessage.value.trim() || isLoading.value) {
        return;
    }

    const messageText = currentMessage.value;
    currentMessage.value = '';
    error.value = null;

    // Add user message to UI immediately
    const userMessage = {
        role: 'user',
        content: messageText,
        created_at: new Date().toISOString()
    };
    messages.value.push(userMessage);

    isLoading.value = true;

    try {
        const response = await fetch(route('chat.stream'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                message: messageText,
                conversation_id: currentConversationId.value
            })
        });

        if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.error || 'Network response was not ok');
        }

        const data = await response.json();

        if (data.success) {
            // Add the assistant message with the complete response
            messages.value.push({
                role: 'assistant',
                content: data.message,
                created_at: new Date().toISOString()
            });

            // Set the conversation ID
            if (data.conversation_id) {
                currentConversationId.value = data.conversation_id;
            }

            // Reload conversations
            await loadConversations();
        } else {
            throw new Error(data.error || 'Failed to get response');
        }
    } catch (err) {
        error.value = err.message || 'Failed to send message. Please try again.';
        // Remove the user message
        messages.value.pop();
        // Restore the message to input
        currentMessage.value = messageText;
    } finally {
        isLoading.value = false;
    }
};

const startNewConversation = () => {
    messages.value = [];
    currentConversationId.value = null;
    error.value = null;
    currentView.value = 'chat';
};

const toggleHistoryView = () => {
    if (currentView.value === 'history') {
        currentView.value = 'chat';
    } else {
        currentView.value = 'history';
        loadConversations();
    }
};

const toggleSelectMode = () => {
    isSelectMode.value = !isSelectMode.value;
    if (!isSelectMode.value) {
        selectedConversations.value = [];
    }
};

const toggleConversationSelection = (conversationId) => {
    const index = selectedConversations.value.indexOf(conversationId);
    if (index > -1) {
        selectedConversations.value.splice(index, 1);
    } else {
        selectedConversations.value.push(conversationId);
    }
};

const selectAllConversations = () => {
    if (selectedConversations.value.length === conversations.value.length) {
        selectedConversations.value = [];
    } else {
        selectedConversations.value = conversations.value.map(c => c.id);
    }
};

const bulkDeleteConversations = async () => {
    if (selectedConversations.value.length === 0) {
        return;
    }

    const count = selectedConversations.value.length;
    if (!confirm(`Are you sure you want to delete ${count} conversation(s)?`)) {
        return;
    }

    try {
        await axios.post(route('chat.conversations.bulk-destroy'), {
            conversation_ids: selectedConversations.value
        });

        // Remove deleted conversations from the list
        conversations.value = conversations.value.filter(
            c => !selectedConversations.value.includes(c.id)
        );

        // If current conversation was deleted, start new
        if (selectedConversations.value.includes(currentConversationId.value)) {
            startNewConversation();
        }

        selectedConversations.value = [];
        isSelectMode.value = false;
    } catch (err) {
        error.value = 'Failed to delete conversations';
    }
};

const deleteConversation = async (conversationId) => {
    if (!confirm('Are you sure you want to delete this conversation?')) {
        return;
    }

    try {
        await axios.delete(route('chat.conversations.destroy', conversationId));
        // Remove from list
        conversations.value = conversations.value.filter(c => c.id !== conversationId);
        // If it's the current conversation, start new
        if (currentConversationId.value === conversationId) {
            startNewConversation();
        }
    } catch (err) {
        error.value = 'Failed to delete conversation';
    }
};

const handleKeyPress = (event) => {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        sendMessage();
    }
};

// Resize handlers
const startResize = (event) => {
    isResizing.value = true;
    event.preventDefault();
};

const handleResize = (event) => {
    if (!isResizing.value) return;

    // Calculate new width based on distance from right edge
    const newWidth = window.innerWidth - event.clientX;

    // Constrain width between min and max
    if (newWidth >= minWidth && newWidth <= maxWidth) {
        panelWidth.value = newWidth;
    }
};

const stopResize = () => {
    isResizing.value = false;
    // Save the new width to localStorage
    localStorage.setItem('chatPanelWidth', panelWidth.value.toString());
};

// Add/remove event listeners for resize
watch(isResizing, (newValue) => {
    if (newValue) {
        document.addEventListener('mousemove', handleResize);
        document.addEventListener('mouseup', stopResize);
        document.body.style.cursor = 'ew-resize';
        document.body.style.userSelect = 'none';
    } else {
        document.removeEventListener('mousemove', handleResize);
        document.removeEventListener('mouseup', stopResize);
        document.body.style.cursor = '';
        document.body.style.userSelect = '';
    }
});
</script>

<template>
    <!-- Backdrop -->
    <div
        v-if="isOpen"
        class="fixed inset-0 z-40 bg-gray-600 bg-opacity-50 transition-opacity"
        @click="emit('close')"
    ></div>

    <!-- Panel -->
    <div
        :class="[
            'fixed top-0 right-0 h-full z-50 bg-white dark:bg-gray-900 shadow-xl transition-transform duration-300 ease-in-out flex flex-col',
            isOpen ? 'translate-x-0' : 'translate-x-full'
        ]"
        :style="{ width: isMobile ? '100%' : panelWidth + 'px' }"
    >
        <!-- Resize Handle (only on desktop) -->
        <div
            v-if="!isMobile"
            class="absolute left-0 top-0 bottom-0 w-1 cursor-ew-resize bg-gray-200 dark:bg-gray-700 hover:bg-blue-500 dark:hover:bg-blue-500 transition-colors group z-10"
            @mousedown="startResize"
            title="Drag to resize"
        >
            <!-- Wider hit area for easier grabbing -->
            <div class="absolute left-0 top-0 bottom-0 w-3 -translate-x-1/2"></div>
        </div>
        <!-- Header -->
        <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-800">
            <div class="flex items-center">
                <ChatBubbleLeftIcon class="w-5 h-5 mr-2 text-gray-600 dark:text-gray-400" />
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                    {{ currentView === 'chat' ? 'Financial Assistant' : 'Chat History' }}
                </h2>
            </div>
            <div class="flex items-center space-x-2">
                <!-- History Toggle Button -->
                <button
                    v-if="conversations.length > 0"
                    @click="toggleHistoryView"
                    :class="[
                        'p-2 rounded-md transition-colors',
                        currentView === 'history'
                            ? 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400'
                            : 'hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-600 dark:text-gray-400'
                    ]"
                    :title="currentView === 'history' ? 'Back to Chat' : 'View History'"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </button>
                <!-- New Conversation Button -->
                <button
                    @click="startNewConversation"
                    class="p-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-600 dark:text-gray-400 transition-colors"
                    title="New Conversation"
                >
                    <PlusCircleIcon class="w-5 h-5" />
                </button>
                <!-- Close Button -->
                <button
                    @click="emit('close')"
                    class="p-2 rounded-md hover:bg-gray-100 dark:hover:bg-gray-800 text-gray-600 dark:text-gray-400 transition-colors"
                    title="Close"
                >
                    <XMarkIcon class="w-5 h-5" />
                </button>
            </div>
        </div>

        <!-- History View -->
        <div
            v-if="currentView === 'history'"
            class="flex-1 overflow-y-auto p-4 flex flex-col"
        >
            <!-- Action Bar -->
            <div class="mb-4 flex items-center justify-between">
                <div class="flex items-center space-x-2">
                    <button
                        @click="toggleSelectMode"
                        :class="[
                            'px-3 py-1.5 text-sm font-medium rounded-md transition-colors',
                            isSelectMode
                                ? 'bg-blue-600 text-white'
                                : 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700'
                        ]"
                    >
                        {{ isSelectMode ? 'Cancel' : 'Select' }}
                    </button>
                    <button
                        v-if="isSelectMode && conversations.length > 0"
                        @click="selectAllConversations"
                        class="px-3 py-1.5 text-sm font-medium rounded-md bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
                    >
                        {{ selectedConversations.length === conversations.length ? 'Deselect All' : 'Select All' }}
                    </button>
                </div>
                <button
                    v-if="isSelectMode && selectedConversations.length > 0"
                    @click="bulkDeleteConversations"
                    class="px-3 py-1.5 text-sm font-medium rounded-md bg-red-600 text-white hover:bg-red-700 transition-colors flex items-center space-x-1"
                >
                    <TrashIcon class="w-4 h-4" />
                    <span>Delete ({{ selectedConversations.length }})</span>
                </button>
            </div>

            <!-- Conversations List -->
            <div v-if="conversations.length > 0" class="space-y-2 flex-1">
                <div
                    v-for="conversation in conversations"
                    :key="conversation.id"
                    :class="[
                        'flex items-start p-3 rounded-lg transition-colors',
                        isSelectMode
                            ? 'bg-gray-50 dark:bg-gray-800'
                            : 'bg-gray-50 dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer group'
                    ]"
                    @click="!isSelectMode && loadConversation(conversation.id)"
                >
                    <!-- Checkbox (in select mode) -->
                    <input
                        v-if="isSelectMode"
                        type="checkbox"
                        :checked="selectedConversations.includes(conversation.id)"
                        @change="toggleConversationSelection(conversation.id)"
                        @click.stop
                        class="mt-1 mr-3 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                    />

                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-900 dark:text-white truncate">
                            {{ conversation.title }}
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            {{ conversation.updated_at }} • {{ conversation.message_count }} messages
                        </p>
                    </div>

                    <!-- Delete Button (not in select mode) -->
                    <button
                        v-if="!isSelectMode"
                        @click.stop="deleteConversation(conversation.id)"
                        class="ml-2 p-1 opacity-0 group-hover:opacity-100 transition-opacity text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
                        title="Delete conversation"
                    >
                        <TrashIcon class="w-4 h-4" />
                    </button>
                </div>
            </div>
            <p v-else class="text-sm text-gray-500 dark:text-gray-400">
                No conversations yet. Start a new one!
            </p>
        </div>

        <!-- Chat View (New or Active Conversation) -->
        <div
            v-else-if="currentView === 'chat' && messages.length === 0"
            class="flex-1 overflow-y-auto p-4"
        >
            <!-- Welcome Message -->
            <div class="p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                <h4 class="text-sm font-medium text-blue-900 dark:text-blue-100 mb-2">How can I help you?</h4>
                <p class="text-sm text-blue-700 dark:text-blue-300">
                    I'm your financial assistant. Ask me about your budget, spending patterns, account balances, or any questions about managing your finances.
                </p>
            </div>
        </div>

        <!-- Messages -->
        <div
            v-else
            ref="messagesContainerRef"
            class="flex-1 overflow-y-auto p-4 space-y-2"
        >
            <ChatMessage
                v-for="(message, index) in messages"
                :key="index"
                :message="message"
            />

            <!-- Loading Indicator -->
            <div v-if="isLoading" class="flex justify-start mb-4">
                <div class="bg-gray-100 dark:bg-gray-700 rounded-lg px-4 py-2">
                    <div class="flex space-x-2">
                        <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0s"></div>
                        <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0.2s"></div>
                        <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0.4s"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Error Message -->
        <div v-if="error" class="px-4 py-2 bg-red-50 dark:bg-red-900/20 border-t border-red-200 dark:border-red-800">
            <p class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>
        </div>

        <!-- Input Area -->
        <div class="border-t border-gray-200 dark:border-gray-800 p-4">
            <div class="flex items-end space-x-2">
                <textarea
                    v-model="currentMessage"
                    @keypress="handleKeyPress"
                    placeholder="Ask me anything about your finances..."
                    rows="3"
                    class="flex-1 resize-none rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 px-3 py-2 text-sm text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    :disabled="isLoading"
                ></textarea>
                <button
                    @click="sendMessage"
                    :disabled="!currentMessage.trim() || isLoading"
                    class="flex-shrink-0 p-3 rounded-lg bg-blue-600 text-white hover:bg-blue-700 disabled:bg-gray-300 dark:disabled:bg-gray-700 disabled:cursor-not-allowed transition-colors"
                    title="Send message"
                >
                    <PaperAirplaneIcon class="w-5 h-5" />
                </button>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                Press Enter to send, Shift+Enter for new line
            </p>
        </div>
    </div>
</template>
