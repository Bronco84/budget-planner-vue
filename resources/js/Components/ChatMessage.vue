<script setup>
import { computed } from 'vue';
import { marked } from 'marked';
import DOMPurify from 'dompurify';

const props = defineProps({
    message: {
        type: Object,
        required: true
    }
});

const isUser = computed(() => props.message.role === 'user');
const isAssistant = computed(() => props.message.role === 'assistant');
const isStreaming = computed(() => props.message.streaming === true);

// Configure marked for better formatting
marked.setOptions({
    breaks: true, // Convert \n to <br>
    gfm: true, // GitHub Flavored Markdown
});

// Render markdown safely
const renderedContent = computed(() => {
    if (isAssistant.value) {
        const html = marked(props.message.content || '');
        return DOMPurify.sanitize(html);
    }
    return props.message.content;
});
</script>

<template>
    <div
        :class="[
            'flex mb-4',
            isUser ? 'justify-end' : 'justify-start'
        ]"
    >
        <div
            :class="[
                'max-w-[80%] px-4 py-2',
                isUser
                    ? 'bg-blue-600 text-white rounded-lg'
                    : 'text-gray-900 dark:text-gray-100'
            ]"
        >
            <!-- Message Content -->
            <div
                v-if="isAssistant"
                class="text-sm break-words markdown-content"
                v-html="renderedContent"
            ></div>
            <div
                v-else
                class="text-sm whitespace-pre-wrap break-words"
            >{{ message.content }}</div>

            <!-- Streaming indicator -->
            <span
                v-if="isStreaming"
                class="inline-block w-2 h-2 ml-1 bg-gray-600 dark:bg-gray-300 rounded-full streaming-pulse"
                style="vertical-align: middle;"
            ></span>

            <!-- Timestamp -->
            <div
                v-if="message.created_at && !isStreaming"
                :class="[
                    'text-xs mt-1',
                    isUser
                        ? 'text-blue-100'
                        : 'text-gray-500 dark:text-gray-400'
                ]"
            >
                {{ new Date(message.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}
            </div>
        </div>
    </div>
</template>

<style scoped>
.markdown-content {
    line-height: 1.6;
}

.markdown-content :deep(h1),
.markdown-content :deep(h2),
.markdown-content :deep(h3),
.markdown-content :deep(h4),
.markdown-content :deep(h5),
.markdown-content :deep(h6) {
    font-weight: 600;
    margin-top: 0.75rem;
    margin-bottom: 0.5rem;
}

.markdown-content :deep(h1) {
    font-size: 1.25rem;
}

.markdown-content :deep(h2) {
    font-size: 1.125rem;
}

.markdown-content :deep(h3) {
    font-size: 1rem;
}

.markdown-content :deep(h4),
.markdown-content :deep(h5),
.markdown-content :deep(h6) {
    font-size: 0.875rem;
}

.markdown-content :deep(p) {
    margin-bottom: 0.75rem;
}

.markdown-content :deep(p:last-child) {
    margin-bottom: 0;
}

.markdown-content :deep(ul),
.markdown-content :deep(ol) {
    margin-left: 1.5rem;
    margin-bottom: 0.75rem;
}

.markdown-content :deep(li) {
    margin-bottom: 0.25rem;
}

.markdown-content :deep(strong) {
    font-weight: 600;
}

.markdown-content :deep(em) {
    font-style: italic;
}

.markdown-content :deep(code) {
    background-color: rgba(0, 0, 0, 0.1);
    padding: 0.125rem 0.25rem;
    border-radius: 0.25rem;
    font-size: 0.875em;
    font-family: 'Courier New', Courier, monospace;
}

.markdown-content :deep(pre) {
    background-color: rgba(0, 0, 0, 0.1);
    padding: 0.75rem;
    border-radius: 0.375rem;
    overflow-x: auto;
    margin-bottom: 0.75rem;
}

.markdown-content :deep(pre code) {
    background-color: transparent;
    padding: 0;
}

.markdown-content :deep(blockquote) {
    border-left: 3px solid rgba(0, 0, 0, 0.2);
    padding-left: 0.75rem;
    margin-left: 0;
    margin-bottom: 0.75rem;
    font-style: italic;
}

.markdown-content :deep(a) {
    color: #3b82f6;
    text-decoration: underline;
}

.markdown-content :deep(a:hover) {
    color: #2563eb;
}

.markdown-content :deep(hr) {
    border: none;
    border-top: 1px solid rgba(0, 0, 0, 0.1);
    margin: 1rem 0;
}

/* Dark mode adjustments */
:deep(.dark) .markdown-content code {
    background-color: rgba(255, 255, 255, 0.1);
}

:deep(.dark) .markdown-content pre {
    background-color: rgba(255, 255, 255, 0.1);
}

:deep(.dark) .markdown-content blockquote {
    border-left-color: rgba(255, 255, 255, 0.2);
}

:deep(.dark) .markdown-content hr {
    border-top-color: rgba(255, 255, 255, 0.1);
}

/* Streaming pulse animation */
.streaming-pulse {
    animation: pulse 1.5s ease-in-out infinite;
}

@keyframes pulse {
    0%, 100% {
        opacity: 0.4;
        transform: scale(1);
    }
    50% {
        opacity: 1;
        transform: scale(1.2);
    }
}
</style>
